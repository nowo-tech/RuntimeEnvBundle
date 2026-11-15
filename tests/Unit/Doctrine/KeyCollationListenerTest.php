<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\Doctrine\KeyCollationListener;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Entity\RuntimeEnvVersion;
use Nowo\RuntimeEnvBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.6 (REQ-SEC-006 rule 3): the key columns of the bundle tables get a binary NO PAD collation on
 * MySQL / MariaDB.
 */
final class KeyCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        yield 'MySQL 8' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), null];
    }

    #[DataProvider('platforms')]
    public function testTheCollationOfEachPlatform(AbstractPlatform $platform, ?string $collation): void
    {
        self::assertSame($collation, KeyCollationListener::collationFor($platform));
    }

    #[DataProvider('platforms')]
    public function testTheKeyColumnsOfTheVariablesAndVersionsTables(AbstractPlatform $platform, ?string $collation): void
    {
        $logger = new RecordingLogger();
        $table  = $this->table();
        $args   = $this->args(RuntimeEnvVariable::class, $table);
        (new KeyCollationListener($this->registry($platform), $logger))->postGenerateSchemaTable($args);

        $result = $args->getClassTable();
        self::assertSame($collation, $this->option($result->getColumn('name'), 'collation'));
        self::assertNull($this->option($result->getColumn('value'), 'collation'), 'only the key columns');

        $versions = (new Schema())->createTable('runtime_env_versions');
        $versions->addColumn('namespace', Types::STRING, ['length' => 64]);
        $versions->addColumn('token', Types::STRING, ['length' => 128]);
        $versionArgs = $this->args(RuntimeEnvVersion::class, $versions);
        (new KeyCollationListener($this->registry($platform), $logger))->postGenerateSchemaTable($versionArgs);
        self::assertSame($collation, $this->option($versionArgs->getClassTable()->getColumn('namespace'), 'collation'));
        self::assertNull($this->option($versionArgs->getClassTable()->getColumn('token'), 'collation'));
        self::assertCount($collation === 'utf8mb4_bin' ? 2 : 0, $logger->records, 'a warning for the PAD SPACE fallback only');
    }

    public function testAnotherEntityOrAManagerThatIsNotAnOrmOneIsLeftAlone(): void
    {
        $table = $this->table();
        $args  = $this->args(stdClass::class, $table);
        (new KeyCollationListener($this->registry(new MySQL80Platform())))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable());

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $args = $this->args(RuntimeEnvVariable::class, $table);
        (new KeyCollationListener($registry))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable());
    }

    public function testInPlaceOnAnOlderDbal(): void
    {
        $table = $this->table();

        self::assertNull(KeyCollationListener::collate($table, 'name', 'utf8mb4_0900_bin', false));
        self::assertSame('utf8mb4_0900_bin', $this->option($table->getColumn('name'), 'collation'));
        self::assertSame('utf8mb4', $this->option($table->getColumn('name'), 'charset'));
    }

    private function option(Column $column, string $name): mixed
    {
        return $column->getPlatformOptions()[$name] ?? null;
    }

    private function table(): Table
    {
        $table = (new Schema())->createTable('runtime_env_variables');
        $table->addColumn('name', Types::STRING, ['length' => 128]);
        $table->addColumn('value', Types::TEXT);

        return $table;
    }

    /**
     * @param class-string $class
     */
    private function args(string $class, Table $table): GenerateSchemaTableEventArgs
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => $table->getName()]);
        foreach (KeyCollationListener::FIELDS[$class] ?? [] as $field) {
            $metadata->mapField(['fieldName' => $field, 'columnName' => $field, 'type' => Types::STRING]);
        }

        return new GenerateSchemaTableEventArgs($metadata, new Schema(), $table);
    }

    private function registry(AbstractPlatform $platform): ManagerRegistry
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }
}
