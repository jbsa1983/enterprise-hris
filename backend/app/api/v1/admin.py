"""Administration: users, roles, permission scopes, organizations, and
statutory-rule configuration. Gated by the `system.admin` permission
(Superadmin holds all permissions).

This is the control center the Superadmin uses to run the system: create and
edit users, assign roles (which define permission scopes) and organization
access, and manage the effective-dated statutory rules used by payroll.
"""
from __future__ import annotations

import secrets
from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException, Request
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_permission
from app.core.rbac import PERMISSIONS
from app.core.security import hash_password
from app.models.enums import EngagementType
from app.models.organization import Enterprise, Organization, OrganizationUser
from app.models.payroll import StatutoryRuleSet
from app.models.person import Engagement, Person
from app.models.user import Role, User
from app.models.user import Permission
from app.schemas.admin import (
    OrganizationIn,
    OrganizationUpdate,
    PasswordReset,
    PermissionOut,
    RoleIn,
    RoleOut,
    RoleUpdate,
    StatutoryRuleIn,
    UserCreate,
    UserOut,
    UserUpdate,
)
from app.services import audit_service

router = APIRouter(prefix="/admin", tags=["admin"])

_ADMIN = Depends(require_permission("system.admin"))
MIN_PASSWORD_LEN = 8


def _check_password(pw: str) -> None:
    if len(pw or "") < MIN_PASSWORD_LEN:
        raise HTTPException(status_code=422, detail=f"Password must be at least {MIN_PASSWORD_LEN} characters")


# --------------------------------------------------------------------------- #
# Permissions catalog
# --------------------------------------------------------------------------- #
@router.get("/permissions", response_model=list[PermissionOut], dependencies=[_ADMIN])
def list_permissions(db: Session = Depends(get_db)) -> list[PermissionOut]:
    rows = db.query(Permission).order_by(Permission.code).all()
    if rows:
        return [PermissionOut(code=p.code, description=p.description) for p in rows]
    # Fall back to the static catalog if not yet seeded.
    return [PermissionOut(code=c, description=d) for c, d in PERMISSIONS.items()]


# --------------------------------------------------------------------------- #
# Roles (permission scopes)
# --------------------------------------------------------------------------- #
def _role_out(db: Session, role: Role) -> RoleOut:
    user_count = db.query(func.count(User.id)).filter(User.roles.any(Role.id == role.id)).scalar() or 0
    return RoleOut(
        id=role.id, name=role.name, description=role.description, is_system=role.is_system,
        permissions=sorted(p.code for p in role.permissions), user_count=int(user_count),
    )


@router.get("/roles", response_model=list[RoleOut], dependencies=[_ADMIN])
def list_roles(db: Session = Depends(get_db)) -> list[RoleOut]:
    return [_role_out(db, r) for r in db.query(Role).order_by(Role.name).all()]


def _resolve_permissions(db: Session, codes: list[str]) -> list[Permission]:
    valid = {p.code: p for p in db.query(Permission).filter(Permission.code.in_(codes)).all()}
    missing = [c for c in codes if c not in valid]
    if missing:
        raise HTTPException(status_code=422, detail=f"Unknown permission(s): {', '.join(missing)}")
    return list(valid.values())


@router.post("/roles", response_model=RoleOut, dependencies=[_ADMIN])
def create_role(payload: RoleIn, request: Request, user: User = _ADMIN, db: Session = Depends(get_db)) -> RoleOut:
    if db.query(Role).filter(Role.name == payload.name).first():
        raise HTTPException(status_code=409, detail="Role name already exists")
    role = Role(name=payload.name, description=payload.description, is_system=False)
    role.permissions = _resolve_permissions(db, payload.permissions)
    db.add(role)
    audit_service.record(db, action="role.create", user=user, entity="role", after={"name": payload.name},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(role)
    return _role_out(db, role)


@router.put("/roles/{role_id}", response_model=RoleOut, dependencies=[_ADMIN])
def update_role(role_id: int, payload: RoleUpdate, request: Request, user: User = _ADMIN,
                db: Session = Depends(get_db)) -> RoleOut:
    role = db.get(Role, role_id)
    if not role:
        raise HTTPException(status_code=404, detail="Role not found")
    before = {"name": role.name, "permissions": sorted(p.code for p in role.permissions)}
    if payload.name is not None:
        role.name = payload.name
    if payload.description is not None:
        role.description = payload.description
    if payload.permissions is not None:
        role.permissions = _resolve_permissions(db, payload.permissions)
    audit_service.record(db, action="role.update", user=user, entity="role", entity_id=role.id,
                         before=before, after={"name": role.name, "permissions": payload.permissions},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(role)
    return _role_out(db, role)


@router.delete("/roles/{role_id}", dependencies=[_ADMIN])
def delete_role(role_id: int, user: User = _ADMIN, db: Session = Depends(get_db)) -> dict:
    role = db.get(Role, role_id)
    if not role:
        raise HTTPException(status_code=404, detail="Role not found")
    if role.is_system:
        raise HTTPException(status_code=409, detail="System roles cannot be deleted")
    if role.users:
        raise HTTPException(status_code=409, detail="Reassign users before deleting this role")
    db.delete(role)
    audit_service.record(db, action="role.delete", user=user, entity="role", entity_id=role_id, commit=False)
    db.commit()
    return {"deleted": role_id}


# --------------------------------------------------------------------------- #
# Users
# --------------------------------------------------------------------------- #
def _user_out(user: User) -> UserOut:
    return UserOut(
        id=user.id, uuid=user.uuid, email=user.email, full_name=user.full_name,
        is_active=user.is_active, is_superadmin=user.is_superadmin, person_id=user.person_id,
        roles=[r.name for r in user.roles],
        organizations=[
            {"organization_id": m.organization_id,
             "name": m.organization.name if m.organization else "",
             "is_primary": m.is_primary}
            for m in user.org_memberships
        ],
    )


def _set_orgs(db: Session, user: User, org_ids: list[int]) -> None:
    db.query(OrganizationUser).filter(OrganizationUser.user_id == user.id).delete()
    db.flush()
    for i, oid in enumerate(dict.fromkeys(org_ids)):
        if db.get(Organization, oid):
            db.add(OrganizationUser(organization_id=oid, user_id=user.id, is_primary=(i == 0)))


def _set_roles(db: Session, user: User, role_ids: list[int]) -> None:
    roles = db.query(Role).filter(Role.id.in_(role_ids)).all() if role_ids else []
    user.roles = roles


@router.get("/users", response_model=list[UserOut], dependencies=[_ADMIN])
def list_users(db: Session = Depends(get_db)) -> list[UserOut]:
    return [_user_out(u) for u in db.query(User).order_by(User.email).all()]


@router.post("/users", response_model=UserOut, dependencies=[_ADMIN])
def create_user(payload: UserCreate, request: Request, actor: User = _ADMIN, db: Session = Depends(get_db)) -> UserOut:
    email = payload.email.strip().lower()
    if db.query(User).filter(User.email == email).first():
        raise HTTPException(status_code=409, detail="Email already exists")
    _check_password(payload.password)
    u = User(email=email, full_name=payload.full_name, hashed_password=hash_password(payload.password),
             is_active=payload.is_active, is_superadmin=payload.is_superadmin, person_id=payload.person_id)
    db.add(u)
    db.flush()
    _set_roles(db, u, payload.role_ids)
    _set_orgs(db, u, payload.organization_ids)
    audit_service.record(db, action="user.create", user=actor, entity="user", entity_id=u.id,
                         after={"email": email, "superadmin": payload.is_superadmin},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(u)
    return _user_out(u)


@router.get("/users/{user_id}", response_model=UserOut, dependencies=[_ADMIN])
def get_user(user_id: int, db: Session = Depends(get_db)) -> UserOut:
    u = db.get(User, user_id)
    if not u:
        raise HTTPException(status_code=404, detail="User not found")
    return _user_out(u)


def _last_superadmin(db: Session, exclude_id: int) -> bool:
    others = db.query(func.count(User.id)).filter(
        User.is_superadmin.is_(True), User.is_active.is_(True), User.id != exclude_id).scalar() or 0
    return others == 0


@router.put("/users/{user_id}", response_model=UserOut, dependencies=[_ADMIN])
def update_user(user_id: int, payload: UserUpdate, request: Request, actor: User = _ADMIN,
                db: Session = Depends(get_db)) -> UserOut:
    u = db.get(User, user_id)
    if not u:
        raise HTTPException(status_code=404, detail="User not found")

    # Guard: don't strip the last active superadmin.
    demoting = (payload.is_superadmin is False and u.is_superadmin) or (payload.is_active is False and u.is_active)
    if demoting and u.is_superadmin and _last_superadmin(db, u.id):
        raise HTTPException(status_code=409, detail="Cannot remove the last active Superadmin")

    if payload.full_name is not None:
        u.full_name = payload.full_name
    if payload.email is not None:
        new_email = payload.email.strip().lower()
        existing = db.query(User).filter(User.email == new_email, User.id != u.id).first()
        if existing:
            raise HTTPException(status_code=409, detail="Email already exists")
        u.email = new_email
    if payload.is_active is not None:
        u.is_active = payload.is_active
    if payload.is_superadmin is not None:
        u.is_superadmin = payload.is_superadmin
    if payload.person_id is not None:
        u.person_id = payload.person_id
    if payload.role_ids is not None:
        _set_roles(db, u, payload.role_ids)
    if payload.organization_ids is not None:
        _set_orgs(db, u, payload.organization_ids)

    audit_service.record(db, action="user.update", user=actor, entity="user", entity_id=u.id,
                         after=payload.model_dump(exclude_none=True),
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(u)
    return _user_out(u)


@router.post("/users/{user_id}/password", dependencies=[_ADMIN])
def reset_password(user_id: int, payload: PasswordReset, actor: User = _ADMIN, db: Session = Depends(get_db)) -> dict:
    u = db.get(User, user_id)
    if not u:
        raise HTTPException(status_code=404, detail="User not found")
    _check_password(payload.password)
    u.hashed_password = hash_password(payload.password)
    audit_service.record(db, action="user.password_reset", user=actor, entity="user", entity_id=u.id, commit=False)
    db.commit()
    return {"ok": True}


@router.post("/provision-ess", dependencies=[_ADMIN])
def provision_ess(request: Request, payload: dict = Body(default={}), actor: User = _ADMIN,
                  db: Session = Depends(get_db)) -> dict:
    """Create self-service logins for employees who don't have one yet.

    - One login per person, linked to their Person record, with the Employee role.
    - Organization access = every org the person is engaged in.
    - Password: `default_password` for all, or an auto-generated one per user
      (returned so the Superadmin can distribute them). Employees change it later.
    """
    default_password = payload.get("default_password")
    if default_password is not None and len(default_password) < MIN_PASSWORD_LEN:
        raise HTTPException(status_code=422, detail=f"default_password must be ≥ {MIN_PASSWORD_LEN} chars")
    org_filter = payload.get("organization_id")
    include_consultants = payload.get("include_consultants", False)

    employee_role = db.query(Role).filter(Role.name == "Employee").first()
    if not employee_role:
        raise HTTPException(status_code=500, detail="Employee role missing — reseed RBAC")

    q = db.query(Engagement).filter(Engagement.status == "ACTIVE")
    if org_filter:
        q = q.filter(Engagement.organization_id == org_filter)
    if not include_consultants:
        q = q.filter(Engagement.engagement_type.notin_(EngagementType.CONSULTANT_TYPES))
    engagements = q.all()

    # Group org access per person.
    persons: dict[int, set[int]] = {}
    for e in engagements:
        persons.setdefault(e.person_id, set()).add(e.organization_id)

    created, skipped, credentials = 0, 0, []
    for person_id, org_ids in persons.items():
        if db.query(User).filter(User.person_id == person_id).first():
            skipped += 1
            continue
        person = db.get(Person, person_id)
        if not person:
            continue
        # Choose a login email.
        email = (person.email or "").strip().lower()
        if not email or db.query(User).filter(User.email == email).first():
            emp_no = next((e.employee_number for e in engagements if e.person_id == person_id and e.employee_number), None)
            email = f"emp{(emp_no or person_id)}@ess.local".lower()
            n = 1
            base = email
            while db.query(User).filter(User.email == email).first():
                email = base.replace("@", f"{n}@")
                n += 1
        pw = default_password or (secrets.token_urlsafe(6) + "A1!")
        u = User(email=email, full_name=person.full_name, hashed_password=hash_password(pw),
                 is_active=True, is_superadmin=False, person_id=person_id)
        u.roles = [employee_role]
        db.add(u)
        db.flush()
        for i, oid in enumerate(sorted(org_ids)):
            db.add(OrganizationUser(organization_id=oid, user_id=u.id, is_primary=(i == 0)))
        created += 1
        credentials.append({"name": person.full_name, "email": email, "temp_password": pw})

    audit_service.record(db, action="ess.provision", user=actor, entity="user",
                         after={"created": created, "skipped": skipped},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"created": created, "skipped": skipped, "credentials": credentials}


@router.post("/users/{user_id}/deactivate", dependencies=[_ADMIN])
def deactivate_user(user_id: int, actor: User = _ADMIN, db: Session = Depends(get_db)) -> dict:
    u = db.get(User, user_id)
    if not u:
        raise HTTPException(status_code=404, detail="User not found")
    if u.id == actor.id:
        raise HTTPException(status_code=409, detail="You cannot deactivate your own account")
    if u.is_superadmin and _last_superadmin(db, u.id):
        raise HTTPException(status_code=409, detail="Cannot deactivate the last active Superadmin")
    u.is_active = False
    audit_service.record(db, action="user.deactivate", user=actor, entity="user", entity_id=u.id, commit=False)
    db.commit()
    return {"ok": True, "is_active": u.is_active}


# --------------------------------------------------------------------------- #
# Organizations (create / update) + enterprises
# --------------------------------------------------------------------------- #
@router.get("/organizations", dependencies=[_ADMIN])
def all_organizations(db: Session = Depends(get_db)) -> list[dict]:
    return [{"id": o.id, "name": o.name, "code": o.code, "is_active": o.is_active,
             "enterprise_id": o.enterprise_id} for o in db.query(Organization).order_by(Organization.name).all()]


@router.post("/organizations", dependencies=[Depends(require_permission("organization.manage"))])
def create_organization(payload: OrganizationIn, request: Request,
                        actor: User = Depends(require_permission("organization.manage")),
                        db: Session = Depends(get_db)) -> dict:
    if db.query(Organization).filter(Organization.code == payload.code).first():
        raise HTTPException(status_code=409, detail="Organization code already exists")
    ent_id = payload.enterprise_id
    if ent_id is None:
        ent = db.query(Enterprise).order_by(Enterprise.id).first()
        if not ent:
            ent = Enterprise(name="Enterprise Group", code="GROUP")
            db.add(ent)
            db.flush()
        ent_id = ent.id
    org = Organization(enterprise_id=ent_id, name=payload.name, code=payload.code,
                       legal_name=payload.legal_name, tin=payload.tin, address=payload.address)
    db.add(org)
    db.flush()
    # Grant the creating admin access to the new org.
    if not actor.is_superadmin:
        db.add(OrganizationUser(organization_id=org.id, user_id=actor.id, is_primary=False))
    audit_service.record(db, action="organization.create", user=actor, organization_id=org.id,
                         entity="organization", entity_id=org.id, after={"code": org.code},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(org)
    return {"id": org.id, "name": org.name, "code": org.code}


@router.put("/organizations/{org_id}", dependencies=[Depends(require_permission("organization.manage"))])
def update_organization(org_id: int, payload: OrganizationUpdate, db: Session = Depends(get_db)) -> dict:
    org = db.get(Organization, org_id)
    if not org:
        raise HTTPException(status_code=404, detail="Organization not found")
    for f in ("name", "legal_name", "tin", "address", "is_active"):
        v = getattr(payload, f)
        if v is not None:
            setattr(org, f, v)
    db.commit()
    return {"id": org.id, "name": org.name, "is_active": org.is_active}


# --------------------------------------------------------------------------- #
# Statutory rules (editable, effective-dated payroll configuration)
# --------------------------------------------------------------------------- #
@router.get("/statutory-rules", dependencies=[_ADMIN])
def list_statutory_rules(db: Session = Depends(get_db)) -> list[dict]:
    rows = db.query(StatutoryRuleSet).order_by(StatutoryRuleSet.rule_name, StatutoryRuleSet.effective_from.desc()).all()
    return [
        {"id": r.id, "rule_name": r.rule_name, "rule_version": r.rule_version,
         "effective_from": r.effective_from.isoformat(),
         "effective_to": r.effective_to.isoformat() if r.effective_to else None,
         "is_prototype_data": r.is_prototype_data, "parameters_json": r.parameters_json, "notes": r.notes}
        for r in rows
    ]


@router.post("/statutory-rules", dependencies=[_ADMIN])
def create_statutory_rule(payload: StatutoryRuleIn, request: Request, actor: User = _ADMIN,
                          db: Session = Depends(get_db)) -> dict:
    rs = StatutoryRuleSet(
        rule_name=payload.rule_name.upper(), rule_version=payload.rule_version,
        effective_from=date.fromisoformat(payload.effective_from),
        effective_to=date.fromisoformat(payload.effective_to) if payload.effective_to else None,
        is_prototype_data=payload.is_prototype_data, parameters_json=payload.parameters_json, notes=payload.notes,
    )
    db.add(rs)
    audit_service.record(db, action="statutory.create", user=actor, entity="statutory_rule_set",
                         after={"rule": rs.rule_name, "version": rs.rule_version},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    db.refresh(rs)
    return {"id": rs.id, "rule_name": rs.rule_name, "rule_version": rs.rule_version}


@router.put("/statutory-rules/{rule_id}", dependencies=[_ADMIN])
def update_statutory_rule(rule_id: int, payload: StatutoryRuleIn, actor: User = _ADMIN,
                          db: Session = Depends(get_db)) -> dict:
    rs = db.get(StatutoryRuleSet, rule_id)
    if not rs:
        raise HTTPException(status_code=404, detail="Rule set not found")
    rs.rule_name = payload.rule_name.upper()
    rs.rule_version = payload.rule_version
    rs.effective_from = date.fromisoformat(payload.effective_from)
    rs.effective_to = date.fromisoformat(payload.effective_to) if payload.effective_to else None
    rs.is_prototype_data = payload.is_prototype_data
    rs.parameters_json = payload.parameters_json
    rs.notes = payload.notes
    db.commit()
    return {"id": rs.id, "rule_name": rs.rule_name}
