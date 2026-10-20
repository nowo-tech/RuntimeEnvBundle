# Usage

## Table of contents

- [Read variables in PHP](#read-variables-in-php)
- [Twig](#twig)
- [Admin CRUD](#admin-crud)
- [What stays in `.env`](#what-stays-in-env)
- [FrankenPHP worker](#frankenphp-worker)

## Read variables in PHP

```php
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;

final class SomeService
{
    public function __construct(private readonly RuntimeEnvBag $runtimeEnv)
    {
    }

    public function callExternalApi(): void
    {
        $token = $this->runtimeEnv->get('EXTERNAL_API_TOKEN');
        if ($token === null) {
            throw new \RuntimeException('EXTERNAL_API_TOKEN is not configured.');
        }
        // ...
    }
}
```

- `has(string $name): bool`
- `get(string $name, ?string $default = null): ?string`
- `all(): array<string, string>` — enabled variables only

## Twig

```twig
{% if runtime_env_has('FEATURE_FLAG_X') %}
  {{ runtime_env('FEATURE_FLAG_X') }}
{% endif %}
```

Do **not** dump secret values into public HTML.

## Admin CRUD

Open `/_runtime_env` (or your `panel.path_prefix`). Names must match `^[A-Z][A-Z0-9_]*$`.

List view always **masks** values. The edit form never renders the stored (decrypted) value: the value field starts empty and masked; leave it blank to keep the current value, type a new one to replace it, or tick **Clear the value** to store an empty string. Empty values are allowed (also when creating a variable).

## What stays in `.env`

Anything required to boot the kernel / connect to the DB / decrypt fields:

- `DATABASE_URL`, `APP_SECRET`
- DoctrineEncrypt secret key path / material
- `FRANKENPHP_MODE`, ports, etc.

## FrankenPHP worker

Safe with or without `kernel.reset` / `services_resetter` (`resetKernel=false`):

- `RuntimeEnvBag` binds its memoized map to the current main HTTP request (`RequestStack` + `WeakReference`). A new main request always reloads from the database.
- Repository reads use `Query::HINT_REFRESH` and resolve the EntityManager via `ManagerRegistry` (closed managers are replaced).
- `RuntimeEnvBag` remains tagged `kernel.reset` for classic Symfony resets.
- After CRUD writes, the bag cache is invalidated immediately for the current process; other workers pick up changes on their next request.

Do **not** store `RuntimeEnvBag::all()` in your own shared service properties — read through the bag each request.

Full audit: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
