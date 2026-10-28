# Usage

## Table of contents

- [Read variables in PHP](#read-variables-in-php)
- [Twig](#twig)
- [Admin CRUD](#admin-crud)
- [What stays in `.env`](#what-stays-in-env)
- [FrankenPHP worker](#frankenphp-worker)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translation overrides](#translation-overrides)

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

## Overriding templates (REQ-TWIG-001)

The manage UI renders `@NowoRuntimeEnvBundle/...` logical names. Application templates under
`templates/bundles/NowoRuntimeEnvBundle/` **always win** over the copies in the package (`TwigPathsPass` prepends that
directory when it exists).

| `<subpath>` | Purpose |
| --- | --- |
| `manage/layout.html.twig` | Default layout of the manage pages (`title` and `body` blocks) |
| `manage/index.html.twig` | Variables list (masked values, edit / delete actions) |
| `manage/form.html.twig` | Create / edit page |
| `manage/_form_fields.html.twig` | Fields of the variable form |

**Procedure:** copy the vendor file from `vendor/nowo-tech/runtime-env-bundle/src/Resources/views/<subpath>` to
`templates/bundles/NowoRuntimeEnvBundle/<subpath>`, edit it, then `php bin/console cache:clear`. The manage UI is only
registered when `web_ui.enabled` (and `panel.enabled`) are true — see [CONFIGURATION.md](CONFIGURATION.md).

**Override vs upgrade (freeze rule).** An application file at the override path always wins and does not pick up vendor changes for that path until you remove or merge it: a full-file override freezes that `<subpath>` on every `composer update` while the rest of the bundle keeps upgrading. Prefer the upgrade-safe options first: `web_ui.layout_template` for your own admin layout, and `templates.index` / `templates.form` pointing at your **own** template names (they can `{% extends '@!NowoRuntimeEnvBundle/manage/index.html.twig' %}` and override one block).

**Recovering upstream after a freeze:** delete (or rename aside) the application override, clear the cache (`php bin/console cache:clear`) and re-apply only the customisation you still need. Diff your copy against the new vendor file under `vendor/nowo-tech/runtime-env-bundle/src/Resources/views/` before re-copying.

## Translation overrides

The admin UI strings use the **`NowoRuntimeEnvBundle`** translation domain, shipped in en, es, fr, de, it, pt and nl (`src/Resources/translations/NowoRuntimeEnvBundle.<locale>.yaml`). To change a string, create `translations/NowoRuntimeEnvBundle.<locale>.yaml` in your application with the same key (application translations take precedence), then clear the cache. Only the keys you override are needed; the rest fall back to the bundle catalogue. `make validate-translations` checks that every bundled locale has the same keys as `en`.

