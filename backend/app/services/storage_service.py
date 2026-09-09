"""Real-time storage monitoring.

Reads the actual mounted filesystem at STORAGE_MONITOR_PATH and reports usage
plus a category breakdown. For the prototype, a simulated total capacity may be
applied so threshold states (NORMAL/ADVISORY/WARNING/CRITICAL) are demonstrable
even on a very large host disk.
"""
from __future__ import annotations

import os
import shutil

from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.storage import StorageMetric

GB = 1024 ** 3


def _threshold(percent: float) -> str:
    if percent >= 90:
        return "CRITICAL"
    if percent >= 80:
        return "WARNING"
    if percent >= 70:
        return "ADVISORY"
    return "NORMAL"


def get_storage_overview(db: Session) -> dict:
    path = settings.storage_monitor_path
    if not os.path.exists(path):
        path = "/"
    usage = shutil.disk_usage(path)

    total = float(usage.total)
    used = float(usage.used)

    # Optional prototype capacity override for demonstrable thresholds.
    sim_gb = settings.storage_simulated_capacity_gb
    if sim_gb and sim_gb > 0:
        total = sim_gb * GB
        # Derive "used" from persisted category metrics if present, else a demo
        # baseline, clamped below the simulated capacity.
        metric_bytes = db.query(func.coalesce(func.sum(StorageMetric.bytes_used), 0)).scalar() or 0
        used = float(metric_bytes) if metric_bytes else total * 0.42
        used = min(used, total * 0.99)

    available = max(total - used, 0)
    percent = round((used / total) * 100, 2) if total else 0.0

    # Category breakdown from persisted metrics (falls back to a demo split).
    rows = (
        db.query(StorageMetric.category, func.sum(StorageMetric.bytes_used))
        .group_by(StorageMetric.category)
        .all()
    )
    categories = {cat: float(val or 0) for cat, val in rows}
    if not categories:
        categories = {
            "employee_documents": used * 0.30,
            "payroll_reports": used * 0.20,
            "recruitment_documents": used * 0.10,
            "training_documents": used * 0.08,
            "database": used * 0.18,
            "backups": used * 0.10,
            "other": used * 0.04,
        }

    return {
        "path": path,
        "used_bytes": used,
        "available_bytes": available,
        "total_bytes": total,
        "percent_used": percent,
        "status": _threshold(percent),
        "used_gb": round(used / GB, 2),
        "available_gb": round(available / GB, 2),
        "total_gb": round(total / GB, 2),
        "breakdown": {k: round(v / GB, 3) for k, v in categories.items()},
        "refresh_seconds": 30,
        "simulated_capacity": bool(sim_gb and sim_gb > 0),
    }


def get_storage_by_organization(db: Session) -> list[dict]:
    rows = (
        db.query(StorageMetric.organization_id, func.sum(StorageMetric.bytes_used))
        .filter(StorageMetric.organization_id.isnot(None))
        .group_by(StorageMetric.organization_id)
        .all()
    )
    return [
        {"organization_id": org_id, "used_gb": round(float(val or 0) / GB, 3)}
        for org_id, val in rows
    ]
