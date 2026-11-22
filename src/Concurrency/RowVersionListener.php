<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Concurrency;

use Doctrine\ORM\Event\PostUpdateEventArgs;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

/**
 * 1.9 (REQ-RUNTIME-003 rule 4): every ORM update of a {@see RuntimeEnvVariable} advances its row version.
 *
 * Doctrine `postUpdate` runs inside the flush's transaction, right after the row's UPDATE: one more atomic
 * `UPDATE … SET row_version = row_version + 1 WHERE id = ?` on the same connection, so a write of the host's code
 * (`RuntimeEnvWriter::update()` / `rename()`, also with `database.transaction: host`) makes the edit pages opened before
 * it stale. The row is already locked by the flush; the ORM itself never writes the column.
 *
 * Stateless.
 */
final class RowVersionListener
{
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $variable = $args->getObject();
        $id       = $variable instanceof RuntimeEnvVariable ? $variable->getId() : null;
        if ($id === null) {
            return;
        }

        $manager = $args->getObjectManager();
        RuntimeEnvRowVersions::advance($manager->getConnection(), $manager->getClassMetadata(RuntimeEnvVariable::class)->getTableName(), $id);
    }
}
