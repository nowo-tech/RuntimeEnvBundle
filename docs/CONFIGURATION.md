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
| `web_ui.enabled` | `true` | `false` removes the HTML manage UI (no routes, controller and access subscriber removed), same effect as `panel.enabled: false`. The bag and Twig helpers keep working. |
| `web_ui.layout_template` | `@NowoRuntimeEnvBundle/manage/layout.html.twig` | Layout the manage pages extend. Passed to the templates as `runtime_env_layout`; your layout must define `title` and `body` blocks. |
| `web_ui.css_framework` / `web_ui.icon_set` | `custom` / `none` | Defaults prepended to NowoUiKitBundle when the host did not set them. |
| `templates.index` / `templates.form` | bundle templates | Twig overrides for the list and form pages. |
| `templates.layout` | `@NowoRuntimeEnvBundle/manage/layout.html.twig` | **Deprecated since 1.1**, use `web_ui.layout_template`. Still honoured (and wins) when set to a non-default value. |

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
        enabled: true
        layout_template: '@NowoRuntimeEnvBundle/manage/layout.html.twig'
        css_framework: bootstrap5
        icon_set: bootstrap-icons
```

## Encryption

Field encryption is owned by **DoctrineEncryptBundle** (`#[Encrypted]` on `RuntimeEnvVariable::$value`). There is no separate cipher in this bundle.
