# FrankenPHP demos (REQ-DEMO-008 / REQ-DEMO-010)

## Table of contents

- [Mode switch](#mode-switch)
- [Worker safety](#worker-safety)
- [Quick start](#quick-start)
- [Start the demo in development](#start-the-demo-in-development)
- [Start the demo in production mode](#start-the-demo-in-production-mode)
- [Troubleshooting](#troubleshooting)

## Mode switch

`FRANKENPHP_MODE` is defined in `demo/symfony8/.env.example` (**default `worker`**).

| Value | Behavior |
|-------|----------|
| `worker` | Long-lived PHP workers (`Caddyfile` with `php_server { worker … }`) |
| `classic` | Per-request PHP (`Caddyfile.dev`) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the container. The entrypoint selects the Caddyfile. **Do not** bake the mode into the Dockerfile `ENV`.

To switch: edit `.env`, then `docker compose up -d` (recreate). A plain `restart` does not reload env.

## Worker safety

- Safe under FrankenPHP worker with **`resetKernel=false`** (scenario B): the bag reloads per main request; the repository re-hydrates from the DB and recovers a closed EntityManager. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
- `RuntimeEnvBag` is still tagged `kernel.reset` (clears the in-memory map when `services_resetter` runs).
- The bundle does **not** mutate `$_ENV` / `putenv`.
- After CRUD save, the bag cache is cleared in the current worker; other workers see changes on their next request (no worker restart).

## Quick start

```bash
cd demo/symfony8
make up
# Demo started at: http://localhost:8026
```

Panel: http://localhost:8026/_runtime_env

## Start the demo in development

```bash
cd demo/symfony8
cp -n .env.example .env        # first run only; set FRANKENPHP_MODE=classic for request mode + hot reload
make up                        # docker compose up -d (entrypoint selects Caddyfile / Caddyfile.dev from FRANKENPHP_MODE)
make setup                     # first run: install + schema + Halite encryption key
```

Open http://localhost:8026. In development `APP_ENV=dev`; `Caddyfile.dev` (classic mode) serves PHP without workers, and `docker/php-dev.ini` (when present) sets `opcache.revalidate_freq=0` so code changes are picked up immediately.

## Start the demo in production mode

The production pattern is the worker Caddyfile (`php_server { worker { file /app/public/index.php; watch } }`), which is the default (`FRANKENPHP_MODE=worker`).

```bash
cd demo/symfony8
# .env: FRANKENPHP_MODE=worker, APP_ENV=prod, APP_DEBUG=0, a real APP_SECRET
make down && make up           # recreate containers so the new env is applied
docker compose exec php php bin/console cache:clear --env=prod
```

In a real deployment, bake the application into the image (no bind mount), keep `FRANKENPHP_MODE=worker`, and warm the cache at build time. Worker-mode state rules are documented in the bundle worker audit (`docs/FRANKENPHP-WORKER-AUDIT.md`, when present).

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Changes in PHP code are not visible | Worker mode keeps the kernel in memory | Use `FRANKENPHP_MODE=classic` in development, or restart the container (`make down && make up`) |
| `FRANKENPHP_MODE` change has no effect | `docker compose restart` does not reload `.env` | Recreate the containers: `make down && make up` (or `docker compose up -d`) |
| Port already in use on `make up` | Another stack uses the demo port | Change `PORT` in `.env` and recreate the containers |
| Bundle changes are not picked up | Composer path repository / autoload cache out of date | Run `make update-bundle` (or `composer dump-autoload` + `cache:clear` in the container) |
| HTTP 502 / container restarts | PHP fatal error at boot (missing `vendor/`, bad config) | Check `docker compose logs php`; run `make install` and `bin/console cache:clear` |
| Stale Twig output in development | Twig cache enabled | Ensure `config/packages/dev/twig.yaml` sets `twig.cache: false` (when the demo ships it) and clear the cache |
| Runtime env values cannot be decrypted / panel shows errors | Missing Halite key or schema | Run `make setup` (install + schema + encryption key) or `make key` / `make db-schema` |
