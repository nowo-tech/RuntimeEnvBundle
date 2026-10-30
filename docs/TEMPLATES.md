# Templates

Pages of RuntimeEnvBundle on the UiKit page contract (REQ-UI-003, UiKit 2.17). The manage CRUD (`web_ui.path_prefix`,
default `/_runtime_env`, enabled with `web_ui.enabled: true`) has two pages. `Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory`
builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the controller; `Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages`
resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`) and the per-page overrides
(`web_ui.templates.<page>`). Both pages extend a UiKit scaffold and fill data blocks only. Contract test:
`tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule).
Twig namespace: `@NowoRuntimeEnvBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Secrets in the pages](#secrets-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Translations](#translations)
- [Layout bridge (pre-1.3 layouts)](#layout-bridge-pre-13-layouts)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoRuntimeEnvBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoRuntimeEnvBundle`)
and `layout: RuntimeEnvPages::layout()`. Labels are `NowoRuntimeEnvBundle` translation keys (`RuntimeEnvPages::label()`).
Deletes are POST forms carrying the CSRF token of `RuntimeEnvDeleteType` (token id `runtime_env_delete_<id>`) behind the
UiKit confirm `<dialog>` (`View\Confirm`); there is no inline `confirm()`.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Variables (`index`) | `manage/index.html.twig` | `page/list` | `ListPage` (header `runtime_env.index.title` + `subtitle`, action New variable (`create`); columns name (required), `masked_value` (value), description (priority 2), enabled; rows = value-free arrays `{id, name, description, enabled}` of the current page (`RuntimeEnvPageFactory::row()`); row actions Edit / Delete (Confirm `runtime_env.confirm.*`); `Pagination` (`web_ui.list_page_size`, route `nowo_runtime_env_index`); `EmptyState` `runtime_env.index.empty` with New variable; `data-testid="runtime-env-variables"`) | `nowo_ui_list_row` (name on `code`; value = fixed mask `••••••••` on `code` (`data-testid="runtime-env-masked-value"`); description or a `muted` dash; enabled on `badge_success` / `badge_neutral`) |
| Variable new / edit (`form`) | `manage/form.html.twig` | `page/form` | `FormPage` (header `runtime_env.form.new_title` / `edit_title`, intro = `new_intro` / the variable name, back link to the list (`data-testid="runtime-env-back"`); form = `RuntimeEnvVariableType`; submit `runtime_env.action.save`; cancel = list; on edit the delete zone with the Delete action; `data-testid="runtime-env-variable-form"`) | `nowo_ui_styles` (`{{ parent() }}` + the bundle stylesheet that masks the value field); `nowo_ui_form_body` is the scaffold default (REQ-TWIG-003 child loop) |

Other variables passed by the controller (for host templates set through `web_ui.templates.<page>`): `variables`
(the same value-free rows as `page.rows`), `delete_forms` (index), `form`, `variable` (value-free row array), `is_edit`
(form), `path_prefix`, `translation_domain` (both), plus the
bridge variables `nowo_runtime_env_legacy_layout` / `nowo_runtime_env_legacy_blocks`.

## Secrets in the pages

- No entity reaches the templates. List rows and the `variable` variable are value-free arrays (`id`, `name`,
  `description`, `enabled`), and the value column key is `masked_value`, which resolves to nothing: even a host
  template that extends the plain UiKit list scaffold (default cells, no `nowo_ui_list_row` override) cannot print a
  value (covered by `PageContractTest::testPlainUiKitListScaffoldNeverPrintsAStoredValue`). The bundle's row prints a
  fixed mask.
- The edit form never renders the stored (decrypted) value back: the value field starts empty, a blank submission keeps
  the current value and `clear_value` sets it to an empty string.
- A typed value is never rendered back either: after a failed submission (validation error, expired CSRF token,
  duplicate name) the value field is printed empty, on create and on edit (`RuntimeEnvVariableType::finishView()`).
- What is typed is masked on create and edit by `bundles/noworuntimeenv/css/runtime-env.css`
  (`textarea.nowo-runtime-env-secret`, `-webkit-text-security`). **Firefox ignores `-webkit-text-security`**: there the
  typed value stays visible while typing (it is still never rendered back). Hosts that need the mask in Firefox can
  map the class to a masking font (e.g. a dot font) in their own stylesheet.
- The form edits a detached copy of the entity: an invalid submission never changes the managed entity.

## Unique pages

- (none) — both manage pages extend a UiKit scaffold.

## Per-page overrides

- `nowo_runtime_env.web_ui.templates.<page>` (`index`, `form`): a host template that extends
  `@!NowoRuntimeEnvBundle/manage/<page>.html.twig` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_form_body`,
  …). Unknown page ids fail the container compilation. Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and
  scaffolds once for every bundle.
- The deprecated `templates.index` / `templates.form` keys still map to these overrides until 2.0.
- Record such overrides in the application's override inventory.

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoRuntimeEnvBundle/<subpath>` (same relative path
as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first (`prependPath`) and the
bundle views after it (`addPath`), so **an application file at the override path always wins**. See
[USAGE](USAGE.md#overriding-templates-req-twig-001) for the freeze rule, the upgrade-safe alternatives and how to
recover upstream after a freeze.

**Overridable subpaths.**

| `<subpath>` | Purpose |
| --- | --- |
| `manage/index.html.twig` | Variables list (list scaffold) |
| `manage/form.html.twig` | Variable create / edit (form scaffold) |
| `manage/_legacy_layout.html.twig` | Deprecated pre-1.3 layout bridge |

Renaming or removing a `<subpath>` or a data block listed here is a breaking change (see [UPGRADING](UPGRADING.md)).

## Bundle partials and assets

The bundle ships no layout, no local pagination, flash, confirm, modal, page header or empty-state partial and no
script: the scaffolds and the UiKit partials (`action`, `confirm`, `empty`, `flashes`, `pagination`, `page_header`,
`scripts`) render them, and the host layout loads the CSS framework. The only bundle asset is
`src/Resources/public/css/runtime-env.css` (installed by `assets:install` as `bundles/noworuntimeenv/css/runtime-env.css`),
loaded by the form page through `nowo_ui_styles` with `{{ parent() }}`.

## Translations

Domain `NowoRuntimeEnvBundle` (REQ-I18N-003), catalogues `src/Resources/translations/NowoRuntimeEnvBundle.<locale>.yaml`
for en, es, fr, it, pt, de and nl (same leaf keys; `make validate-translations`). Keys live under `runtime_env.*`
(page titles, columns, actions, confirm dialog, flashes, form labels / help). Override any key in the application
(`translations/NowoRuntimeEnvBundle.<locale>.yaml`).

## Layout bridge (pre-1.3 layouts)

`web_ui.layout_template` (or the deprecated `templates.layout`) may still point at a pre-1.3 layout (no
`nowo_ui_page_header` block, e.g. the project `base.html.twig` rendering `body`): `RuntimeEnvPages` then wraps it in
`manage/_legacy_layout.html.twig` (maps the canonical blocks into `body`, `title`, `stylesheets`, `javascripts`) and
triggers a deprecation. The value `@NowoRuntimeEnvBundle/manage/layout.html.twig` (the removed pre-1.3 default) is
mapped to null. The bridge is removed in 2.0.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()` and no `ignoredMissingTranslations()`.
