# Memory

## Preferences

- Treat the local Docker Compose stack as the canonical validation environment for this project.
- Run migrations, application commands, and runtime checks inside the relevant containers.
- Verify changed user flows against `http://localhost:8091` in the live local app, including visible UI and runtime logs when relevant.
- Host-only tests, static analysis, and builds remain required quality gates, but do not substitute for Docker runtime verification.
