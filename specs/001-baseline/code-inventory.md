# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/runtime-env-bundle`  
**Last audited**: 2026-11-27 (1.10 provider files: [`002-dbal-query-provider`](../002-dbal-query-provider/spec.md))

This file proves that **every production source artifact** under `src/` is inventoried. PHPUnit under `tests/` is out of scope unless promoted in the spec.

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoRuntimeEnvBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/RuntimeEnvExtension.php` | DI extension + parameters | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig path registration | FR-DI-002 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fails closed without form CSRF (REQ-SEC-005) | FR-SEC-001 |
| `Concurrency/RowVersionListener.php` | 1.9: every ORM update of a variable advances its row version (`postUpdate`, inside the flush) | FR-CONC-001 |
| `Concurrency/RuntimeEnvRowVersions.php` | 1.9: row version read, compare-and-set + write in one runner unit, claim unit for registered settings | FR-CONC-001 |
| `Concurrency/StaleEditException.php` | 1.9: stale edit page (domain 409 `RUNTIME_ENV_STALE_EDIT`) | FR-CONC-001 |
| `Controller/RuntimeEnvManageController.php` | Admin CRUD; 1.9: stale edit answer | FR-UI-001, FR-CONC-001 |
| `Doctrine/RuntimeEnvMetadataListener.php` | Table prefix metadata | FR-ORM-001 |
| `Entity/RuntimeEnvVariable.php` | Encrypted entity | FR-ORM-002, FR-CONC-001 |
| `EventSubscriber/RuntimeEnvAccessSubscriber.php` | Panel access gate (priority 7, firewall-aware) | FR-SEC-001, FR-WORKER-003 |
| `Form/RuntimeEnvDeleteType.php` | CSRF delete form (REQ-TWIG-005) | FR-UI-004 |
| `Form/RuntimeEnvVariableType.php` | CRUD form | FR-UI-002, FR-CONC-001 |
| `Repository/RuntimeEnvVariableRepositoryInterface.php` | Repository contract | FR-REP-001 |
| `Repository/PaginatedRuntimeEnvVariableRepositoryInterface.php` | Paginated list reads (REQ-PERF-001) | FR-REP-001, FR-UI-001 |
| `Repository/DoctrineOrmRuntimeEnvVariableRepository.php` | ORM repository (registry + HINT_REFRESH, pagination) | FR-REP-002, FR-WORKER-002 |
| `Routing/RuntimeEnvRouteLoader.php` | Route type `nowo_runtime_env` (reads the controller `#[Route]`s, prepends `web_ui.path_prefix`) | FR-RT-001 |
| `Security/RuntimeEnvAccessCheckerInterface.php` | Access checker contract | FR-SEC-002 |
| `Security/AllowAllRuntimeEnvAccessChecker.php` | Allow-all checker | FR-SEC-003 |
| `Security/ConfigurableRuntimeEnvAccessChecker.php` | Role-based checker (REQ-UI-002) | FR-SEC-004 |
| `Service/RuntimeEnvBag.php` | Runtime read API + request-scoped memo + ResetInterface | FR-RT-002, FR-WORKER-001 |
| `Service/RuntimeEnvWriter.php` | Persist / invalidate | FR-RT-003 |
| `Twig/RuntimeEnvTwigExtension.php` | `runtime_env()` Twig function | FR-TWIG-001 |
| `Ui/RuntimeEnvPages.php` | UiKit page plumbing: layout, legacy bridge, per-page overrides (REQ-UI-003) | FR-UI-003 |
| `Ui/RuntimeEnvPageFactory.php` | UiKit view models of the manage pages (REQ-UI-003) | FR-UI-003 |
| `DependencyInjection/Compiler/AfterCommitMiddlewarePass.php` | After-commit middleware on the bundle connection (1.5) | FR-ORM-003 |
| `DependencyInjection/Compiler/RuntimeEnvEntityManagerPass.php` | `database.entity_manager` mapping and ownership (1.5) | FR-ORM-003 |
| `DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | `allow_unauthenticated` only in debug | FR-SEC-001 |
| `DependencyInjection/Compiler/RuntimeSettingPortsPass.php` | 1.6: runtime-setting ports bound if absent; admin updater path | FR-RS-001, FR-RS-004 |
| `Doctrine/KeyCollationListener.php` | 1.6: binary NO PAD key columns on MySQL / MariaDB (REQ-SEC-006) | FR-RS-005 |
| `Event/RuntimeEnvVariableChangedEvent.php` | After-commit change event (1.5) | FR-ORM-003 |
| `Persistence/AfterCommitConnection.php` | After-commit transaction observer (1.5) | FR-ORM-003 |
| `Persistence/AfterCommitDriver.php` | After-commit transaction observer (1.5) | FR-ORM-003 |
| `Persistence/AfterCommitMiddleware.php` | After-commit transaction observer (1.5) | FR-ORM-003 |
| `Persistence/OwnEntityManagerSubscriber.php` | Bundle-owned manager cleared per unit (1.5) | FR-WORKER-002 |
| `Persistence/RuntimeEnvOutbox.php` | Committed / pending events (1.5) | FR-ORM-003 |
| `Persistence/RuntimeEnvPendingEventsInterface.php` | Pending events port (1.5) | FR-ORM-003 |
| `Persistence/RuntimeEnvTransaction.php` | Unit of work boundary (1.5, 1.5.1) | FR-ORM-003, FR-WORKER-001 |
| `Persistence/TransactionObserverInterface.php` | Transaction observer port (1.5) | FR-ORM-003 |
| `RuntimeSetting/DoctrineEncryptValueCipher.php` | 1.6: entity-format encryption for DBAL writes | FR-RS-002 |
| `RuntimeSetting/Entity/RuntimeEnvVersion.php` | 1.6: `env` version token (mapped only when enabled) | FR-RS-003 |
| `RuntimeSetting/RuntimeEnvAdminSettings.php` | 1.6: admin edits of registered settings through RuntimeSettingUpdater; 1.10: provider settings refused | FR-RS-004, FR-PRV-005 |
| `RuntimeSetting/Provider/DbalQueryValueProvider.php` | 1.10: read-only DBAL query provider (one row, bounded, sanitised failures) | FR-PRV-002, FR-PRV-003, FR-PRV-004 |
| `RuntimeSetting/Provider/CacheScopeEnvVarProcessor.php` | 1.10: `%env(nowo_runtime_env_scope:...)%`, fixed-length hash of an env constant for the provider cache scope | FR-PRV-009 |
| `RuntimeSetting/Provider/ReadOnlyQuery.php` | 1.10: validated read-only SQL + parameters + columns | FR-PRV-001 |
| `RuntimeSetting/Provider/InvalidReadOnlyQuery.php` | 1.10: configuration refusal (names the rule, never the SQL) | FR-PRV-001 |
| `DependencyInjection/Compiler/DbalQueryProvidersPass.php` | 1.10: provider connections must be declared | FR-PRV-006 |
| `RuntimeSetting/RuntimeEnvSettingStore.php` | 1.6: contract source and writer (DBAL, own transaction, rotation, purge, audit) | FR-RS-001, FR-RS-002, FR-RS-003, FR-CONC-001 |
| `RuntimeSetting/RuntimeEnvValueCipherInterface.php` | 1.6: cipher port of the store | FR-RS-002 |
| `RuntimeSetting/RuntimeEnvVersionBumper.php` | 1.6: version bump in the 1.5 writer's flush | FR-RS-003 |
| `RuntimeSetting/RuntimeEnvVariableId.php` | 1.9: id of a store insert from the entity's sequence (PostgreSQL with DBAL 3) | FR-RS-001 |
| `RuntimeSetting/RuntimeEnvVersionRow.php` | 1.6 (audit QT-L-3): version row of a namespace seeded with a statement that cannot conflict (serialises first writes) | FR-RS-003 |
| `Service/RuntimeEnvReaderInterface.php` | Name-based read port (1.5) | FR-RT-002 |
| `Service/RuntimeEnvWriterInterface.php` | Name-based write port (1.5) | FR-RT-003 |
| `Transaction/StorageFailureListener.php` | 1.6, 1.8: 409 + CONFLICT_001 / CONFLICT_002 (or 503) and the domain 409 on the bundle's routes | FR-RS-006 |
| `Worker/UnitOfWorkFrames.php` | Outermost unit tracking (1.5.1) | FR-WORKER-001 |

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
| `Resources/views/manage/_legacy_layout.html.twig` | Deprecated pre-1.3 layout bridge | FR-UI-003 |
| `Resources/views/manage/index.html.twig` | Admin list (UiKit list scaffold) | FR-UI-003 |
| `Resources/views/manage/form.html.twig` | Admin form (UiKit form scaffold) | FR-UI-003 |
| `Resources/public/css/runtime-env.css` | Value field masking | FR-UI-003 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 57 | 57 |
| YAML config / translations / Twig / CSS | 12 | 12 |
| **Total `src/` artifacts** | **69** | **69** |
