# Migrations

Alembic is scaffolded here for future schema versioning.

The **prototype** creates its schema at startup via SQLAlchemy
`Base.metadata.create_all` (see `app/seed/bootstrap.py`) for reliability and
speed. When the model set stabilizes, switch to Alembic-managed migrations:

```bash
# from the backend/ directory (or inside the backend container)
alembic revision --autogenerate -m "initial schema"
alembic upgrade head
```

`migrations/env.py` reads `DATABASE_URL` from the environment and targets the
same metadata used by the app, so autogenerate reflects all models.
