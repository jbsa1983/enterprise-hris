"""Audit module.

The append-only audit writer lives in `app/services/audit_service.py`; the read
API is `app/api/v1/audit.py`. This package is reserved for richer audit tooling
(diff builders, retention policies) as the system grows.
"""
