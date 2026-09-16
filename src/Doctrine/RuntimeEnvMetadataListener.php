<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

/**
 * Applies configurable table name for {@see RuntimeEnvVariable}.
 */
final readonly class RuntimeEnvMetadataListener
{
    public function __construct(
        private string $variablesTableName,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== RuntimeEnvVariable::class) {
            return;
        }

        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->variablesTableName]));
    }
}
