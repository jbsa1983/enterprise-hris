"""Application configuration, loaded from environment variables.

Secrets come from `.env` (see `.env.example`). Never hard-code credentials.
"""
from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", case_sensitive=False, extra="ignore")

    # General
    env: str = "development"
    project_name: str = "GEEK Group — Enterprise HRIS"
    api_v1_prefix: str = "/api/v1"

    # Database
    database_url: str = "postgresql+psycopg2://hris:hris_dev_password@postgres:5432/hris"

    # Redis / Celery
    redis_url: str = "redis://redis:6379/0"
    celery_broker_url: str = "redis://redis:6379/1"
    celery_result_backend: str = "redis://redis:6379/2"

    # MinIO
    minio_endpoint: str = "minio:9000"
    minio_root_user: str = "minioadmin"
    minio_root_password: str = "minioadmin"
    minio_bucket: str = "hris-documents"
    minio_secure: bool = False

    # Auth
    jwt_secret_key: str = "change-me"
    jwt_algorithm: str = "HS256"
    access_token_expire_minutes: int = 30
    refresh_token_expire_days: int = 7

    # Storage monitoring
    storage_monitor_path: str = "/data"
    storage_simulated_capacity_gb: float = 50.0

    # Seeding
    #   demo    → full demo dataset (companies, employees, payroll, …)
    #   minimal → RBAC + a single Superadmin only (recommended for real deployments)
    #   none    → create tables only, seed nothing
    seed_mode: str = "demo"
    seed_on_startup: bool = True
    default_admin_email: str = "admin@demo-hris.local"
    default_admin_password: str = "Admin123!"

    # CORS. Kept as a plain comma-separated string so pydantic-settings never
    # tries to JSON-decode it (behaviour differs across versions); split via the
    # `cors_origins` property below.
    backend_cors_origins: str = "http://localhost:8080,http://localhost:3000"

    @property
    def cors_origins(self) -> list[str]:
        return [o.strip() for o in self.backend_cors_origins.split(",") if o.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
