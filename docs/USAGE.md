# Usage

## Table of contents

- [Read variables in PHP](#read-variables-in-php)
- [Twig](#twig)
- [Standard Symfony and DDD / hexagonal hosts](#standard-symfony-and-ddd--hexagonal-hosts)
- [Admin CRUD](#admin-crud)
- [Runtime settings (1.6)](#runtime-settings-16)
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

## Standard Symfony and DDD / hexagonal hosts

Both paths are supported (1.5); the standard one is the zero-config default.

**Standard host.** Inject `RuntimeEnvBag` (or `RuntimeEnvReaderInterface`) to read and `RuntimeEnvWriter` (or
`RuntimeEnvWriterInterface`) to write; the variables live on your default entity manager and each write is flushed
right away. React to changes (never the value) with the event:

```php
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class AuditRuntimeEnv
{
    public function __invoke(RuntimeEnvVariableChangedEvent $event): void
    {
        // $event->name, $event->action (created|updated|renamed|deleted), $event->at, $event->previousName
    }
}
```

**DDD / hexagonal host.** Depend on the ports only and keep the variables off your domain manager:

```yaml
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default: { connection: default, mappings: { App: { type: xml, dir: '%kernel.project_dir%/config/doctrine', prefix: 'App\\Domain' } } }
            runtime_env: { connection: default }   # or its own connection
nowo_runtime_env:
    database:
        entity_manager: runtime_env   # mapped there only; your managers are never flushed, reset or cleared
        transaction: host             # optional: the writer does not flush, your use case commits
```

```php
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvReaderInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;

final readonly class RotateApiToken
{
    public function __construct(private RuntimeEnvWriterInterface $writer)
    {
    }

    public function __invoke(string $token): void
    {
        $this->writer->updateByName('EXTERNAL_API_TOKEN', $token, 'Rotated by the domain', true);
    }
}
```

- With a dedicated manager the bundle owns it: reset at the next request / message when a failed write closed it,
  cleared at the end of every request, message and command. Your default manager is never reset by the bundle.
- In both modes the event follows the real transaction: dropped when your transaction (or a savepoint) rolls back,
  dispatched after your outermost commit, on the next `postFlush` outside a transaction, at the end of the request /
  message / command, or by `RuntimeEnvPendingEventsInterface::dispatchPending()`. With `transaction: host`, events
  of writes never flushed are discarded.
- Values stay encrypted by DoctrineEncryptBundle on the bundle's connection. If you put the bundle manager on its own
  connection and restrict `nowo_doctrine_encrypt.connections`, list that connection too (or leave the list empty):
  otherwise the values are stored in plaintext.
- XML-mapped hosts: the bundle entity keeps its own attribute mapping (prepended by the bundle); nothing to extend.

## Twig

```twig
{% if runtime_env_has('FEATURE_FLAG_X') %}
  {{ runtime_env('FEATURE_FLAG_X') }}
{% endif %}
```

Do **not** dump secret values into public HTML.

## Admin CRUD

Open `/_runtime_env` (or your `web_ui.path_prefix`). Names must match `^[A-Z][A-Z0-9_]*$`.

The list is paginated (`web_ui.list_page_size`, default 50; `?page=N`) and always **masks** values. Deleting a
variable opens the UiKit confirm dialog (no inline `confirm()`); the delete is a CSRF-protected POST. The pages follow
the UiKit page contract (layout `nowo_ui_kit.layouts.admin`, list / form scaffolds) — see [TEMPLATES.md](TEMPLATES.md).
The create / edit and delete forms are Symfony Forms and **FormKit Types** (`RuntimeEnvVariableType`, `RuntimeEnvDeleteType`;
profile `nowo_form_kit.profiles.runtime_env`, see [CONFIGURATION](CONFIGURATION.md#formkit-profile)) rendered with
`form_start` / the `{% for child in form %}{% if not child.rendered %}{{ form_row(child) }}{% endif %}{% endfor %}` loop /
`form_end`; overrides must not reintroduce raw `<form>` / `<input>` (REQ-TWIG-005).
 The edit form never renders the stored (decrypted) value: the value field starts empty and masked; leave it blank to keep the current value, type a new one to replace it, or tick **Clear the value** to store an empty string. Empty values are allowed (also when creating a variable).

## Runtime settings (1.6)

With `runtime_setting.enabled: true` this table is the database backend of
[nowo-tech/runtime-setting-contract](https://github.com/nowo-tech/runtime-setting-contract): a bundle setting that lives
in `.env` (for example ChatKit's `retention_days`) can come from here, change live and rotate without downtime.

```yaml
nowo_runtime_env:
    runtime_setting: { enabled: true }
nowo_runtime_setting:            # NowoRuntimeSettingBundle (register it in config/bundles.php)
    cache_pool: cache.app        # recommended in production; secrets never go there
    version_ttl: 5
nowo_chat_kit:
    retention_days: '%env(int:CHAT_RETENTION_DAYS)%'
    runtime:
        retention_days: { source: db_then_env, key: CHAT_RETENTION_DAYS, on_error: fail_closed }
```

- **Key** = the environment variable name. `namespace.key` ids are not stored here (bind another source for them).
- **Reads** (`RuntimeSettingResolver`) see enabled variables only; a disabled variable is unset (the configured env value
  applies with `db_then_env`). `RuntimeEnvBag` and `runtime_env()` keep reading the same table as before.
- **Writes** through `nowo:runtime-setting:set|unset|rotate|purge`, through `RuntimeSettingUpdater`, or through the
  admin UI. For a variable that a bundle registered as a writable setting, the admin UI uses the updater too: the
  bundle's validator refuses invalid values (shown on the value field), secrets are never echoed, and the audit actor is
  `user:<identifier>`. `unset` disables the variable (its row and description stay).
- **Rotation**: `rotate` keeps the old value encrypted in `previous_value` until `--keep-previous` expires;
  `RuntimeSettingResolver::candidates()` returns both inside the window. Expired previous values are deleted by every
  write and by `nowo:runtime-setting:purge` (schedule it).
- **Live change**: every write (store, 1.5 writer, admin UI, purge; also a write that changes nothing) changes the `env`
  version token in the same transaction, so every worker sees the change within `version_ttl`, without a restart.
- **Transactions**: the store writes through DBAL in its own transaction on the connection of
  `database.entity_manager`, never flushing your entity manager; called inside a transaction you opened, it refuses
  (`RuntimeSettingNotWritable`). A deadlock on the admin routes answers 503 with `Retry-After`; a duplicate name 409.
- **Audit**: after the commit, an `info` log line (actor, name, action, fingerprints; none for secrets) and
  `RuntimeEnvVariableChangedEvent`, as for 1.5 writes.
- **Encryption**: values use DoctrineEncryptBundle's default profile (the entity's `#[Encrypted]` format). Replace
  `RuntimeEnvValueCipherInterface` to change that.

## What stays in `.env`

Anything required to boot the kernel / connect to the DB / decrypt fields:

- `DATABASE_URL`, `APP_SECRET`
- DoctrineEncrypt secret key path / material
- `FRANKENPHP_MODE`, ports, etc.

## FrankenPHP worker

Safe with or without `kernel.reset` / `services_resetter` (`resetKernel=false`):

- `RuntimeEnvBag` binds its memoized map to the current main HTTP request (`RequestStack` + `WeakReference`). A new main request always reloads from the database.
- Repository reads use `Query::HINT_REFRESH` and resolve the EntityManager via `ManagerRegistry`. 1.5: a closed manager is replaced (and the manager cleared at every boundary) only when it is the bundle's own `database.entity_manager`, never your default one.
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
| `manage/index.html.twig` | Variables list (UiKit list scaffold; masked values, edit / delete actions) |
| `manage/form.html.twig` | Create / edit page (UiKit form scaffold) |
| `manage/_legacy_layout.html.twig` | Deprecated bridge for pre-1.3 layouts (removed in 2.0) |

**Procedure:** copy the vendor file from `vendor/nowo-tech/runtime-env-bundle/src/Resources/views/<subpath>` to
`templates/bundles/NowoRuntimeEnvBundle/<subpath>`, edit it, then `php bin/console cache:clear`. The manage UI is only
registered when `web_ui.enabled` is true — see [CONFIGURATION.md](CONFIGURATION.md).

**Override vs upgrade (freeze rule).** An application file at the override path always wins and does not pick up vendor changes for that path until you remove or merge it: a full-file override freezes that `<subpath>` on every `composer update` while the rest of the bundle keeps upgrading. Prefer the upgrade-safe options first: `nowo_ui_kit.layouts.admin` (or `web_ui.layout_template`) for your own admin layout, `nowo_ui_kit.theme` for classes / tokens (one theme for every Nowo bundle), and `web_ui.templates.index` / `web_ui.templates.form` pointing at your **own** template names (they can `{% extends '@!NowoRuntimeEnvBundle/manage/index.html.twig' %}` and override one data block such as `nowo_ui_list_row`). Never print `row.value` / `variable.value` in an override: values are secrets.

**Recovering upstream after a freeze:** delete (or rename aside) the application override, clear the cache (`php bin/console cache:clear`) and re-apply only the customisation you still need. Diff your copy against the new vendor file under `vendor/nowo-tech/runtime-env-bundle/src/Resources/views/` before re-copying.

## Translation overrides

The admin UI strings use the **`NowoRuntimeEnvBundle`** translation domain, shipped in en, es, fr, de, it, pt and nl (`src/Resources/translations/NowoRuntimeEnvBundle.<locale>.yaml`). To change a string, create `translations/NowoRuntimeEnvBundle.<locale>.yaml` in your application with the same key (application translations take precedence), then clear the cache. Only the keys you override are needed; the rest fall back to the bundle catalogue. `make validate-translations` checks that every bundled locale has the same keys as `en`.

