"""FastAPI dependencies: current user, permission checks, org-context scoping.

Organization scoping is centralized here (`require_org_access`) so no endpoint
relies on frontend filtering — every org-scoped request re-verifies membership.
"""
from __future__ import annotations

from fastapi import Depends, Header, HTTPException, status
from fastapi.security import OAuth2PasswordBearer
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.security import ACCESS_TOKEN_TYPE, decode_token
from app.models.user import User

oauth2_scheme = OAuth2PasswordBearer(tokenUrl="/api/v1/auth/login", auto_error=False)


def get_current_user(
    token: str | None = Depends(oauth2_scheme),
    db: Session = Depends(get_db),
) -> User:
    credentials_exc = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Could not validate credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    if not token:
        raise credentials_exc
    try:
        payload = decode_token(token)
    except Exception:  # noqa: BLE001 — any decode failure = unauthorized
        raise credentials_exc
    if payload.get("type") != ACCESS_TOKEN_TYPE:
        raise credentials_exc
    user_id = payload.get("sub")
    if not user_id:
        raise credentials_exc
    user = db.get(User, int(user_id))
    if not user or not user.is_active:
        raise credentials_exc
    return user


def require_permission(*required: str):
    """Dependency factory enforcing that the user holds ALL given permissions."""

    def checker(user: User = Depends(get_current_user)) -> User:
        codes = user.permission_codes
        if "*" in codes:
            return user
        missing = [p for p in required if p not in codes]
        if missing:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Missing permission(s): {', '.join(missing)}",
            )
        return user

    return checker


def require_org_access(
    organization_id: int,
    user: User = Depends(get_current_user),
) -> int:
    """Verify the user may act within `organization_id`. Returns the id.

    Superadmins and enterprise-wide roles pass through; everyone else must have
    an explicit OrganizationUser grant.
    """
    if user.is_superadmin:
        return organization_id
    if organization_id not in user.accessible_org_ids:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="You do not have access to this organization",
        )
    return organization_id
