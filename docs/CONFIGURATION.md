# Configuration

Alias: `nowo_runtime_env`

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch for bag + panel |
| `table_prefix` | `runtime_env` | Table becomes `{prefix}_variables` |
| `database.entity_manager` | `default` | Doctrine EM name |
| `panel.enabled` | `true` | Expose CRUD controllers |
| `panel.path_prefix` | `/_runtime_env` | URL prefix |
| `security.access_roles` | `[ROLE_ADMIN]` | REQ-UI-002 |
| `security.access_checker` | `null` | Optional service id |
| `security.allow_unauthenticated` | `false` | Demo only |
| `web_ui.*` | (see tree) | REQ-UI-001 layout / CSS / icons |
| `templates.*` | bundle defaults | Twig overrides |

## Example

```yaml
nowo_runtime_env:
    enabled: true
    table_prefix: runtime_env
    database:
        entity_manager: default
    panel:
        enabled: true
        path_prefix: /_runtime_env
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
    web_ui:
        css_framework: bootstrap5
        icon_set: bootstrap-icons
```

## Encryption

Field encryption is owned by **DoctrineEncryptBundle** (`#[Encrypted]` on `RuntimeEnvVariable::$value`). There is no separate cipher in this bundle.
