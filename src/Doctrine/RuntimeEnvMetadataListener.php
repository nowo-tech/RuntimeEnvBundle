<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Entity\RuntimeEnvVersion;

use function array_merge;

/**
 * Applies the configurable table names (REQ-ORM-002) and, with `runtime_setting.enabled` (1.6), maps the two nullable
 * rotation columns of {@see RuntimeEnvVariable} (`previous_value`, `previous_expires_at`). Without the feature they stay
 * unmapped, so `doctrine:schema:update` / `doctrine:migrations:diff` neither add nor need them.
 */
final readonly class RuntimeEnvMetadataListener
{
    public function __construct(
        private string $variablesTableName,
        private bool $runtimeSettings = false,
        private ?string $versionsTableName = null,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() === RuntimeEnvVersion::class && $this->versionsTableName !== null) {
            $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->versionsTableName]));

            return;
        }

        if ($metadata->getName() !== RuntimeEnvVariable::class) {
            return;
        }

        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->variablesTableName]));

        if ($this->runtimeSettings && !$metadata->hasField('previousValue')) {
            $metadata->mapField(['fieldName' => 'previousValue', 'columnName' => 'previous_value', 'type' => 'text', 'nullable' => true]);
            $metadata->mapField(['fieldName' => 'previousExpiresAt', 'columnName' => 'previous_expires_at', 'type' => 'datetime_immutable', 'nullable' => true]);
        }
    }
}
