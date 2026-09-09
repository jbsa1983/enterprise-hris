"""MinIO document storage helper (payslip PDFs, bank files, resumes, etc.).

All object storage goes through here so the rest of the app never talks to MinIO
directly. Failures are non-fatal for reads (callers can re-render from snapshots).
"""
from __future__ import annotations

import io

from minio import Minio

from app.core.config import settings

_client: Minio | None = None


def client() -> Minio:
    global _client
    if _client is None:
        _client = Minio(
            settings.minio_endpoint,
            access_key=settings.minio_root_user,
            secret_key=settings.minio_root_password,
            secure=settings.minio_secure,
        )
    return _client


def ensure_bucket() -> None:
    c = client()
    if not c.bucket_exists(settings.minio_bucket):
        c.make_bucket(settings.minio_bucket)


def put_bytes(object_key: str, data: bytes, content_type: str = "application/octet-stream") -> str:
    ensure_bucket()
    client().put_object(
        settings.minio_bucket,
        object_key,
        io.BytesIO(data),
        length=len(data),
        content_type=content_type,
    )
    return object_key


def get_bytes(object_key: str) -> bytes | None:
    try:
        resp = client().get_object(settings.minio_bucket, object_key)
        try:
            return resp.read()
        finally:
            resp.close()
            resp.release_conn()
    except Exception:  # noqa: BLE001 — treat any read failure as "not available"
        return None
