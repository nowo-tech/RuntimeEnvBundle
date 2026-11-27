<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;

use function preg_replace;
use function substr;

/**
 * 1.6: the admin UI's path for variables that a bundle registered as runtime settings (a writable
 * `RuntimeSettingDefinition` whose id is the variable name). Their value changes go through
 * {@see RuntimeSettingUpdater}, exactly like `nowo:runtime-setting:set|unset`: same validator, same bounds, same secret
 * rules, same audit (actor `user:<identifier>`), same version bump and event. Other variables keep the free-form 1.5
 * path. Registered only when `runtime_setting.enabled` is true and the host registered NowoRuntimeSettingBundle.
 *
 * Stateless.
 */
final readonly class RuntimeEnvAdminSettings
{
    public function __construct(
        private RuntimeSettingRegistry $registry,
        private RuntimeSettingUpdater $updater,
        private RuntimeEnvSettingStore $store,
    ) {
    }

    /**
     * Whether `$name` is a registered setting whose source can be written (source db / db_then_env). Settings read from
     * a read-only provider (runtime-setting-contract 1.1) are managed too, so that every change of them is refused
     * with RuntimeSettingNotWritable instead of falling back to the free-form path.
     */
    public function manages(string $name): bool
    {
        return $this->registry->has($name) && $this->registry->get($name)->isWritable();
    }

    /**
     * A new variable: its value through the updater (validated first), then its description; created disabled when
     * `$enabled` is false.
     *
     * @throws RuntimeSettingExceptionInterface the value was refused (nothing written)
     */
    public function create(string $name, string $value, ?string $description, bool $enabled, string $actor): void
    {
        // 1.10: a setting read from a read-only provider is refused before anything is written (description included).
        $this->updater->writableDefinition($name);
        // @igor-ignore - Calls the updater (a port); no request data kept in this service.
        $this->updater->set($name, $value, $actor);
        if (!$enabled) {
            // @igor-ignore - Calls the updater (a port); no request data kept in this service.
            $this->updater->unset($name, $actor);
        }
        // @igor-ignore - Writes through the store (I/O); no request data kept in this service.
        $this->store->updateDescription($name, $description);
    }

    /**
     * An edited variable.
     *
     * @param string|null $newValue the typed value ('' when the admin cleared it), null to keep the stored one
     * @param string $storedValue the stored (decrypted) value, re-validated when a disabled variable is enabled again
     *
     * @throws RuntimeSettingExceptionInterface the value was refused (nothing written)
     */
    public function update(string $name, ?string $newValue, bool $enabled, bool $wasEnabled, string $storedValue, ?string $description, string $actor): void
    {
        // 1.10: a setting read from a read-only provider is refused before anything is written (description included).
        $this->updater->writableDefinition($name);
        if (!$enabled) {
            // @igor-ignore - Calls the updater (a port); no request data kept in this service.
            $this->updater->unset($name, $actor);
        } elseif ($newValue !== null) {
            // @igor-ignore - Calls the updater (a port); no request data kept in this service.
            $this->updater->set($name, $newValue, $actor);
        } elseif (!$wasEnabled) {
            // @igor-ignore - Calls the updater (a port); no request data kept in this service.
            $this->updater->set($name, $storedValue, $actor);
        }
        // @igor-ignore - Writes through the store (I/O); no request data kept in this service.
        $this->store->updateDescription($name, $description);
    }

    /**
     * `user:<identifier>` reduced to the contract's actor alphabet (at most 128 characters).
     */
    public static function actor(?string $userIdentifier): string
    {
        $name = substr((string) preg_replace('/[^A-Za-z0-9._@+-]/', '_', (string) $userIdentifier), 0, 128);

        return 'user:' . ($name === '' ? 'anonymous' : $name);
    }
}
