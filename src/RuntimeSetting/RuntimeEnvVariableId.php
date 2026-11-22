<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

use function is_string;

/**
 * 1.9 (audit ZJ-M1 follow-up): the id of a variable row the store inserts through DBAL.
 *
 * The ORM generates ids with an identity column on most stacks, which DBAL fills itself. On PostgreSQL with DBAL 3,
 * ORM 3 maps the entity's `AUTO` id to a SEQUENCE (`{table}_id_seq`) and the column has no default, so a DBAL insert
 * without an id fails. The store then takes the next value of the entity's own sequence, read from its mapping.
 *
 * Stateless.
 */
final class RuntimeEnvVariableId
{
    /**
     * `['id' => next value]` when the entity's ids come from a sequence, else `[]` (the database fills the id).
     *
     * @return array<string, int>
     */
    public static function forInsert(EntityManagerInterface $manager, Connection $connection): array
    {
        $metadata = $manager->getClassMetadata(RuntimeEnvVariable::class);
        $sequence = $metadata->sequenceGeneratorDefinition['sequenceName'] ?? null;
        if (!$metadata->isIdGeneratorSequence() || !is_string($sequence) || $sequence === '') {
            return [];
        }

        return ['id' => (int) $connection->fetchOne($connection->getDatabasePlatform()->getSequenceNextValSQL($sequence))];
    }
}
