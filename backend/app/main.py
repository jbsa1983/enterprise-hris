"""FastAPI application entrypoint."""
from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api.v1 import api_router
from app.core.config import settings

app = FastAPI(
    title=settings.project_name,
    version="0.1.0",
    description=(
        "GEEK Group Enterprise HRIS API. Note: seeded statutory values are "
        "illustrative and must be validated before running real Philippine payroll."
    ),
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(api_router, prefix=settings.api_v1_prefix)


@app.get("/health", tags=["system"])
def health() -> dict:
    return {"status": "ok", "service": "hris-backend", "version": app.version}


@app.get("/api/v1/health", tags=["system"])
def health_v1() -> dict:
    return {"status": "ok", "service": "hris-backend", "version": app.version}
