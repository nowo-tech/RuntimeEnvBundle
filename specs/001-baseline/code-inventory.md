# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/runtime-env-bundle`  
**Last audited**: 2026-09-16

This file proves that **every production source artifact** under `src/` is inventoried. PHPUnit under `tests/` is out of scope unless promoted in the spec.

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoRuntimeEnvBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/RuntimeEnvExtension.php` | DI extension + parameters | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig path registration | FR-DI-002 |
| `Controller/RuntimeEnvManageController.php` | Admin CRUD | FR-UI-001 |
| `Doctrine/RuntimeEnvMetadataListener.php` | Table prefix metadata | FR-ORM-001 |
| `Entity/RuntimeEnvVariable.php` | Encrypted entity | FR-ORM-002 |
| `EventSubscriber/RuntimeEnvAccessSubscriber.php` | Panel access gate | FR-SEC-001 |
| `Form/RuntimeEnvDeleteType.php` | CSRF delete form (REQ-TWIG-005) | FR-UI-004 |
| `Form/RuntimeEnvVariableType.php` | CRUD form | FR-UI-002 |
| `Repository/RuntimeEnvVariableRepositoryInterface.php` | Repository contract | FR-REP-001 |
| `Repository/DoctrineOrmRuntimeEnvVariableRepository.php` | ORM repository | FR-REP-002 |
| `Routing/RuntimeEnvRouteLoader.php` | Route type `nowo_runtime_env` | FR-RT-001 |
| `Security/RuntimeEnvAccessCheckerInterface.php` | Access checker contract | FR-SEC-002 |
| `Security/AllowAllRuntimeEnvAccessChecker.php` | Allow-all checker | FR-SEC-003 |
| `Security/ConfigurableRuntimeEnvAccessChecker.php` | Role-based checker (REQ-UI-002) | FR-SEC-004 |
| `Service/RuntimeEnvBag.php` | Runtime read API + ResetInterface | FR-RT-002 |
| `Service/RuntimeEnvWriter.php` | Persist / invalidate | FR-RT-003 |
| `Twig/RuntimeEnvTwigExtension.php` | `runtime_env()` Twig function | FR-TWIG-001 |

## Symfony config / resources (`src/Resources/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |
| `Resources/translations/NowoRuntimeEnvBundle.de.yaml` | i18n DE | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.en.yaml` | i18n EN | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.es.yaml` | i18n ES | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.fr.yaml` | i18n FR | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.it.yaml` | i18n IT | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.nl.yaml` | i18n NL | FR-I18N-001 |
| `Resources/translations/NowoRuntimeEnvBundle.pt.yaml` | i18n PT | FR-I18N-001 |
| `Resources/views/manage/_form_fields.html.twig` | Shared form fields (REQ-TWIG-003) | FR-UI-003 |
| `Resources/views/manage/layout.html.twig` | Admin layout | FR-UI-003 |
| `Resources/views/manage/index.html.twig` | Admin list | FR-UI-003 |
| `Resources/views/manage/form.html.twig` | Admin form | FR-UI-003 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 19 | 19 |
| YAML config / translations / Twig | 12 | 12 |
| **Total `src/` artifacts** | **31** | **31** |
