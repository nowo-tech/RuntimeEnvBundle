# Implementation plan — 002 DBAL query provider

1. `ReadOnlyQuery` + `InvalidReadOnlyQuery`: compile-time validation of SQL, parameters and columns.
2. `DbalQueryValueProvider`: bind, execute, at most one row, bounded strings, rotation columns, sanitised failures.
3. Configuration `providers.<name>`; extension registers tagged services, refuses env placeholders; `DbalQueryProvidersPass` checks connections.
4. `RuntimeEnvAdminSettings` refuses provider-backed settings before any write.
5. Tests: unit (validator, provider on SQLite, extension, pass, admin), database group (MySQL, MariaDB, PostgreSQL), kept-alive kernel.
6. Docs: README, CONFIGURATION, USAGE, SECURITY, worker audit, CHANGELOG, UPGRADING, code inventory.
