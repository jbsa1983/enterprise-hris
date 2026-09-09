from __future__ import annotations

from pydantic import BaseModel


class LoginRequest(BaseModel):
    # Plain str (not EmailStr) to avoid the optional email-validator dependency
    # in the prototype; the login handler normalizes with .lower().
    email: str
    password: str


class TokenPair(BaseModel):
    access_token: str
    refresh_token: str
    token_type: str = "bearer"


class RefreshRequest(BaseModel):
    refresh_token: str


class OrgMembership(BaseModel):
    organization_id: int
    name: str
    code: str
    is_primary: bool


class CurrentUser(BaseModel):
    id: int
    uuid: str
    email: str
    full_name: str
    is_superadmin: bool
    roles: list[str]
    permissions: list[str]
    organizations: list[OrgMembership]
