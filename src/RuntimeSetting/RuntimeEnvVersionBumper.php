<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Entity\RuntimeEnvVersion;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * 1.6: changes the `env` version token in the SAME unit of work as a write of {@see RuntimeEnvWriter} (create, update,
 * rename, delete through the legacy API or the admin UI): it schedules the new token on `database.entity_manager`
 * and the writer's flush (or the host's, with `database.transaction: host`) commits both together. A rolled-back
 * write therefore never publishes a token; a committed one always does, so the resolver's shared cache never serves a
 * value older than the token.
 *
 * Only registered with `runtime_setting.enabled`. Stateless.
 */
final readonly class RuntimeEnvVersionBumper
{
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityManagerName,
        private bool $ownsManager = true,
        private string $versionsTable = 'runtime_env_versions',
    ) {
    }

    /**
     * Schedules a new, never reused token for the `env` namespace (persisted with the caller's flush).
     */
    public function bump(): void
    {
        $entityManager = $this->registry->getManager($this->entityManagerName);
        if ($this->ownsManager && $entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $entityManager = $this->registry->resetManager($this->entityManagerName);
        }
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName));
        }

        // The row exists before the ORM looks for it (audit QT-L-3): no INSERT race between two first writes, and a
        // failed flush cannot close the manager on a duplicate key. Safe inside the host's transaction.
        RuntimeEnvVersionRow::ensure($entityManager->getConnection(), $this->versionsTable, RuntimeSettingId::ENV_NAMESPACE);
        $token   = self::newToken();
        $version = $entityManager->find(RuntimeEnvVersion::class, RuntimeSettingId::ENV_NAMESPACE);
        if ($version instanceof RuntimeEnvVersion) {
            $version->setToken($token);

            return;
        }

        $entityManager->persist(new RuntimeEnvVersion(RuntimeSettingId::ENV_NAMESPACE, $token));
    }

    /**
     * 128 random bits: never handed out twice, not even after a rollback.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
