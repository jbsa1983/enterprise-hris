"""Celery application for background jobs.

Run by the `worker` service:  celery -A app.worker.celery_app worker
Phase-1 scope is minimal; payslip PDF batches, bank-file generation, and report
exports will be enqueued here in later phases.
"""
from __future__ import annotations

from celery import Celery

from app.core.config import settings

celery_app = Celery(
    "hris",
    broker=settings.celery_broker_url,
    backend=settings.celery_result_backend,
)
celery_app.conf.update(
    task_serializer="json",
    result_serializer="json",
    accept_content=["json"],
    timezone="Asia/Manila",
    enable_utc=True,
)


@celery_app.task(name="hris.ping")
def ping() -> str:
    """Health/liveness task used to verify the worker is processing jobs."""
    return "pong"
