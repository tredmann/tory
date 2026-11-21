# Production and releases

`docker/Dockerfile` has a `prod` target that bakes in Composer dependencies and the built Vite assets, runs as `www-data` and runs `queue:work` instead of `queue:listen`:

```sh
docker build -f docker/Dockerfile --target prod -t tory:prod .
```

The image has no `.env`; configure it through environment variables:

- `APP_KEY` and `APP_URL`; the image refuses to start without `APP_KEY`. Stored provider keys are encrypted with it.
- `DB_*` for a Postgres database with the pgvector extension (the items migration creates the extension, which needs a role allowed to do so). Set `AUTO_MIGRATE=true` to migrate on start.
- `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` with the PEM contents from `php artisan passport:keys`; the MCP server needs them and the image refuses to start without them.
- `TRUSTED_PROXIES` behind a TLS-terminating proxy, otherwise URLs come out as `http`.
- A volume on `/app/storage`, writable for uid 33.

On a fresh installation create the first user as described under "Create a user" in the README. An embedding provider must be reachable for semantic search; without one, search falls back to keywords and `items:embed` reports failures. Keep `DB_QUEUE_RETRY_AFTER` above the import job timeout (200 seconds; the default is 300), otherwise a second worker can re-run a running extraction.

Pushing a version tag like `1.0.0` runs `.github/workflows/release.yml`, which builds the `prod` target for arm64 and pushes it to a DigitalOcean container registry named by the repository secrets `DIGITALOCEAN_ACCESS_TOKEN` and `DIGITALOCEAN_REGISTRY`. Change the workflow to use another registry or platform.
