# Worker

The background worker runs from the **backend image** (it shares the same
`app/` code and models) and is defined as the `worker` service in
`docker-compose.yml`:

```
celery -A app.worker.celery_app worker --loglevel=info
```

The Celery app lives at [`backend/app/worker.py`](../backend/app/worker.py).

Later phases enqueue here:

- Bulk payslip PDF generation (Phase 4)
- Bank file generation (Phase 5)
- Report exports (Phase 5)
- Scheduled storage-metric snapshots

This directory is kept as a placeholder so the repository structure matches the
specification; there is no separate worker image to build.
