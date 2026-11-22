<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvVariableId;
use PHPUnit\Framework\TestCase;

/**
 * 1.9: the store's DBAL insert takes the id from the entity's sequence where the ORM maps one (PostgreSQL + DBAL 3);
 * the real-database job covers it on PostgreSQL 16 with DBAL 3.
 */
final class RuntimeEnvVariableIdTest extends TestCase
{
    public function testASequenceMappedIdIsTakenFromTheSequence(): void
    {
        $metadata = new ClassMetadata(RuntimeEnvVariable::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'runtime_env_variables_id_seq', 'allocationSize' => '1', 'initialValue' => '1']);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('fetchOne')->with("SELECT NEXTVAL('runtime_env_variables_id_seq')")->willReturn('42');

        self::assertSame(['id' => 42], RuntimeEnvVariableId::forInsert($this->manager($metadata), $connection));
    }

    public function testAnIdentityColumnLetsTheDatabaseFillTheId(): void
    {
        $metadata = new ClassMetadata(RuntimeEnvVariable::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        self::assertSame([], RuntimeEnvVariableId::forInsert($this->manager($metadata), $connection));
    }

    /**
     * @param ClassMetadata<RuntimeEnvVariable> $metadata
     */
    private function manager(ClassMetadata $metadata): EntityManagerInterface
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);

        return $manager;
    }
}
