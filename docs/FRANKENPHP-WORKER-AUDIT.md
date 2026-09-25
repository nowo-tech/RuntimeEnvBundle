# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/runtime-env-bundle` (`symfony-bundle`) |
| Audited revision | `v1.0.2` |
| Audit date | 2026-09-25 |
| Method | Manual review of every PHP file under `src/` (bag, writer, repository, Doctrine listener, subscriber, access checkers, controller, forms, Twig extension, route loader, DI extension, compiler pass, `Resources/config/services.yaml`); listener order checked in the compiled demo container |
| **Verdict** | ✅ **Viable under scenario B** — the bag reloads per main request, the repository re-hydrates from the database and recovers a closed EntityManager, and the access check never reuses a stale token (was: ⚠️ viable with conditions) |
| Remediation (2026-09-23) | W-01, W-02, W-03 resolved (`RuntimeEnvBag`, `DoctrineOrmRuntimeEnvVariableRepository`, `RuntimeEnvExtension`, `RuntimeEnvAccessSubscriber`); regression tests simulate consecutive requests / two workers without `reset()` (`tests/Unit/Service/RuntimeEnvBagTest.php`, `tests/Unit/EventSubscriber/RuntimeEnvAccessSubscriberTest.php`, `tests/Unit/Repository/…`, `tests/Integration/Repository/DoctrineOrmRuntimeEnvVariableRepositoryWorkerTest.php` on SQLite) |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ (was ⚠️ Medium) | `RuntimeEnvBag::$values` is bound to the current main request (`WeakReference`), so it never outlives the request; still cleared by `reset()` and after writes |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `RuntimeEnvBag` implements `ResetInterface` and is tagged `kernel.reset`; `reset()` clears its only mutable property |
| Request / user / locale captured in services | ✅ | Token and roles are read at call time; the token is only trusted when a firewall matched the request |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; the bag deliberately does not write `$_ENV` / `putenv()` |
| Doctrine / EntityManager | ✅ (was ⚠️ Medium) | Manager resolved per call via `ManagerRegistry` and reset when closed; reads use `Query::HINT_REFRESH`. Clearing the application's identity map between requests remains the application's responsibility under B (the bundle never calls `clear()`) |
| Output, headers, `exit`, shutdown functions | ✅ | None; controller returns `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | None beyond the Doctrine connection |
| Memory growth across requests | ✅ | Bag holds at most one map of enabled variables; identity map holds at most one managed object per variable row (refreshed in place), other growth is Doctrine/app-owned |
| Blocking I/O and timeouts | ✅ | One `SELECT` of enabled rows per main request when a value is read; database timeouts are owned by the DBAL connection |
| Third-party static state | ⚠️ Info | Decryption is done by `nowo-tech/doctrine-encrypt-bundle` on entity load; its own worker behaviour is out of scope here |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist:16-17` |

A worker demo exists: `demo/symfony8/docker/frankenphp/Caddyfile:17` declares a `worker` block (`Caddyfile.dev` runs in classic mode).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag` | yes, `kernel.reset` | `?array $values` + `WeakReference` to its main request | ✅ (reset) | ✅ reloads per main request (W-01 resolved) |
| `Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter` | yes | none (`readonly`); clears the bag after each write | ✅ | ✅ (only clears its own thread's bag) |
| `Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository` | yes | none (`readonly` `ManagerRegistry` + manager name) | ✅ | ✅ refresh reads, closed EM replaced (W-02 resolved) |
| `Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener` | yes | none (`final readonly`) | ✅ | ✅ |
| `Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber` | yes | none (`readonly`) | ✅ | ✅ (W-03 resolved) |
| `ConfigurableRuntimeEnvAccessChecker` / `AllowAllRuntimeEnvAccessChecker` | yes | none (`readonly` / no properties) | ✅ | ✅ |
| `Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController` | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\RuntimeEnvBundle\Twig\RuntimeEnvTwigExtension` | yes | none; delegates to the bag | ✅ | ✅ via bag |
| `RuntimeEnvVariableType`, `RuntimeEnvDeleteType` (form types) | yes | stateless | ✅ | ✅ |
| `Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader` | yes | `bool $loaded` (build/warmup-time guard, standard Symfony loader pattern) | ✅ | ✅ |

`RuntimeEnvVariable` is a Doctrine entity; it is never stored in a service property (the bag keeps only `name => value` strings).

## Findings

### W-01 — Memoized variables are never refreshed without reset, and writes only invalidate one worker thread (Medium, scenario B only)

- **Where:** `src/Service/RuntimeEnvBag.php:23` (`$values`), `:51-67` (`all()` loads once and memoizes), `:72-80` (`clearRuntimeCache()` / `reset()`); tag added in `src/DependencyInjection/RuntimeEnvExtension.php:92-96`; invalidation in `src/Service/RuntimeEnvWriter.php:36`, `:48`, `:63`, `:72`.
- **Worker impact:** under A the bag is emptied after every request, so every request reads fresh values from the database. Under B the map is loaded once per worker thread and kept forever. `RuntimeEnvWriter` clears the cache only of the bag in the thread that handled the admin write; the other FrankenPHP worker threads (each has its own container) keep serving the old values — including disabled or deleted secrets — until they restart. No per-user data is involved, so this is stale configuration, not a cross-user leak.
- **Recommendation:** keep `services_resetter` enabled. If the bag must also be safe under B, add a cheap version check (for example `MAX(updated_at)` or a counter row) before returning the memoized map, or clear the bag on `kernel.request` for the main request.
- **Status:** Resolved — `RuntimeEnvBag` takes an optional `RequestStack` (wired to `request_stack` in `src/DependencyInjection/RuntimeEnvExtension.php`) and stores a `WeakReference` to the main request the map was built for; a different main request (or switching between HTTP and non-HTTP context) reloads from the repository, sub-requests reuse the map (`src/Service/RuntimeEnvBag.php`). Because the map lives at most one request and reads bypass the identity map (W-02), a write in worker thread 1 is visible in worker thread 2 on its next request. `reset()` / `kernel.reset` are kept for scenario A. Outside HTTP (CLI, Messenger) the map is still memoized until `reset()` / a write, as before.

### W-02 — Correctness depends on DoctrineBundle resetting the EntityManager (Medium, scenario B only)

- **Where:** `src/DependencyInjection/RuntimeEnvExtension.php:80-84` (the repository gets `doctrine.orm.<name>_entity_manager` directly); `src/Repository/DoctrineOrmRuntimeEnvVariableRepository.php:39-47` (`findAllEnabled()`), `:49-63` (`save()` / `remove()` flush).
- **Worker impact:** under A, DoctrineBundle's `kernel.reset` hook clears the identity map and replaces a closed EntityManager between requests, so the injected service always works. Under B two problems appear. (1) The identity map is never cleared: `findBy()` returns already managed `RuntimeEnvVariable` objects without re-hydrating changed columns, so even after `clearRuntimeCache()` a thread can rebuild the bag from stale entities written by another thread. (2) If a flush fails — for example two admins creating the same name at the same time hit the `uniq_runtime_env_name` unique constraint after the `findOneByName()` check in `RuntimeEnvWriter::create()` (`src/Service/RuntimeEnvWriter.php:30-35`) — the EntityManager is closed and every later request of that worker that uses it fails with "EntityManager is closed".
- **Recommendation:** keep `services_resetter` enabled. For extra robustness, inject `ManagerRegistry` and resolve the manager per call (`getManager($name)`, calling `resetManager()` when it is closed), and read the bag with a query that bypasses the identity map (DQL with `Query::HINT_REFRESH` or a scalar `SELECT name, value`), keeping in mind that decryption currently happens on entity hydration.
- **Status:** Resolved — `src/Repository/DoctrineOrmRuntimeEnvVariableRepository.php` now receives `ManagerRegistry` + the configured manager name (the legacy `EntityManagerInterface` argument stays optional for BC), resolves the manager per call, calls `resetManager()` when it is closed, and resets it when `flush()` throws and leaves it closed (the exception is rethrown). All reads (`find`, `findOneByName`, `findAllOrderedByName`, `findAllEnabled`) are DQL with `Query::HINT_REFRESH`, so managed entities are re-hydrated and `postLoad` decryption runs again. Verified with SQLite and two EntityManagers (one per worker thread) in `tests/Integration/Repository/DoctrineOrmRuntimeEnvVariableRepositoryWorkerTest.php` (fails without the hint). The bundle never calls `clear()`; clearing the application's identity map between requests remains the application's responsibility under B.

### W-03 — Access subscriber shares priority 8 with the security firewall (Low)

- **Where:** `src/EventSubscriber/RuntimeEnvAccessSubscriber.php:32` (`KernelEvents::REQUEST => ['onKernelRequest', 8]`), `:51-54` (reads `TokenStorage` and calls the access checker).
- **Worker impact:** Symfony's firewall also listens on `kernel.request` with priority 8, so the relative order depends on bundle registration order. In the compiled demo container the firewall listener is registered first, which is correct. If an application registers this bundle before `SecurityBundle`, the check runs before the firewall: under A the token is `null` (access denied for everyone); under B `TokenStorage` still holds the previous request's token until the firewall runs, so the decision could be made with another user's roles. The docblock already requires the host to firewall `path_prefix`, which limits the impact.
- **Recommendation:** use a priority lower than 8 (for example `7` or `0`) so the check always runs after the firewall has set the current token.
- **Status:** Resolved — priority is now `RuntimeEnvAccessSubscriber::PRIORITY = 7`, and the token is only read when the current request carries `_firewall_context` (a firewall matched); otherwise the checker receives `null` (`src/EventSubscriber/RuntimeEnvAccessSubscriber.php`). Residual framework responsibility: a firewall with `security: false` over `path_prefix` does not refresh the token storage, so do not put the panel behind such a firewall unless `allow_unauthenticated` is enabled.

No other findings. There are no static properties, no superglobals, no output outside `Response` objects and no persistent resources opened by the bundle.

## Usage recommendations in worker mode

- Keeping `services_resetter` enabled is still recommended (framework services such as the token storage and the EntityManager identity map rely on it), but the bundle no longer depends on it.
- Protect `path_prefix` with a firewall and `access_control` in the host application, as documented.
- After changing variables in the admin panel, other worker threads see the change on their next request, with or without resets.
- Do not store `RuntimeEnvBag::all()` results in your own shared service properties; read through the bag on each request.
- Also audit `nowo-tech/doctrine-encrypt-bundle` for worker mode, because it decrypts values on entity load.

## Re-audit triggers

Re-run this audit when a change adds: a second cache to `RuntimeEnvBag` or the writer, writes to `$_ENV` / `putenv()`, a change to how the EntityManager is injected, a new listener or subscriber, or a change of the access subscriber priority.
