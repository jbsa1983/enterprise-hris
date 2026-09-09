from __future__ import annotations

from typing import Optional

from pydantic import BaseModel, ConfigDict


class ORMBase(BaseModel):
    model_config = ConfigDict(from_attributes=True)


# --- Permissions -------------------------------------------------------------
class PermissionOut(BaseModel):
    code: str
    description: Optional[str] = None


# --- Roles -------------------------------------------------------------------
class RoleIn(BaseModel):
    name: str
    description: Optional[str] = None
    permissions: list[str] = []


class RoleUpdate(BaseModel):
    name: Optional[str] = None
    description: Optional[str] = None
    permissions: Optional[list[str]] = None


class RoleOut(BaseModel):
    id: int
    name: str
    description: Optional[str] = None
    is_system: bool
    permissions: list[str]
    user_count: int


# --- Users -------------------------------------------------------------------
class OrgAccessOut(BaseModel):
    organization_id: int
    name: str
    is_primary: bool


class UserOut(BaseModel):
    id: int
    uuid: str
    email: str
    full_name: str
    is_active: bool
    is_superadmin: bool
    person_id: Optional[int] = None
    roles: list[str]
    organizations: list[OrgAccessOut]


class UserCreate(BaseModel):
    email: str
    full_name: str
    password: str
    is_superadmin: bool = False
    is_active: bool = True
    role_ids: list[int] = []
    organization_ids: list[int] = []
    person_id: Optional[int] = None


class UserUpdate(BaseModel):
    full_name: Optional[str] = None
    email: Optional[str] = None
    is_active: Optional[bool] = None
    is_superadmin: Optional[bool] = None
    role_ids: Optional[list[int]] = None
    organization_ids: Optional[list[int]] = None
    person_id: Optional[int] = None


class PasswordReset(BaseModel):
    password: str


# --- Organizations (admin create/update) ------------------------------------
class OrganizationIn(BaseModel):
    name: str
    code: str
    legal_name: Optional[str] = None
    tin: Optional[str] = None
    address: Optional[str] = None
    enterprise_id: Optional[int] = None


class OrganizationUpdate(BaseModel):
    name: Optional[str] = None
    legal_name: Optional[str] = None
    tin: Optional[str] = None
    address: Optional[str] = None
    is_active: Optional[bool] = None


# --- Statutory rules (editable payroll configuration) -----------------------
class StatutoryRuleIn(BaseModel):
    rule_name: str            # BIR / SSS / PHIC / HDMF / EC / ...
    rule_version: str
    effective_from: str       # ISO date
    effective_to: Optional[str] = None
    is_prototype_data: bool = False
    parameters_json: dict
    notes: Optional[str] = None
