# FrankenPHP demos (REQ-DEMO-008 / REQ-DEMO-010)

## Mode switch

`FRANKENPHP_MODE` is defined in `demo/symfony8/.env.example` (**default `worker`**).

| Value | Behavior |
|-------|----------|
| `worker` | Long-lived PHP workers (`Caddyfile` with `php_server { worker … }`) |
| `classic` | Per-request PHP (`Caddyfile.dev`) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the container. The entrypoint selects the Caddyfile. **Do not** bake the mode into the Dockerfile `ENV`.

To switch: edit `.env`, then `docker compose up -d` (recreate). A plain `restart` does not reload env.

## Worker safety

- `RuntimeEnvBag` is tagged `kernel.reset` and clears its in-memory map between requests.
- The bundle does **not** mutate `$_ENV` / `putenv`.
- After CRUD save, the bag cache is cleared so the next request reloads from DB (no worker restart).

## Quick start

```bash
cd demo/symfony8
make up
# Demo started at: http://localhost:8026
```

Panel: http://localhost:8026/_runtime_env
