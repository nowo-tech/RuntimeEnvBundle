<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RuntimeEnvMetadataListenerTest extends TestCase
{
    public function testLoadClassMetadataIgnoresOtherEntities(): void
    {
        $metadata                = new ClassMetadata(stdClass::class);
        $metadata->table['name'] = 'original_table';
        $args                    = new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class));

        (new RuntimeEnvMetadataListener('runtime_env_variables'))->loadClassMetadata($args);

        self::assertSame('original_table', $metadata->table['name']);
    }

    public function testLoadClassMetadataOverridesRuntimeEnvTableName(): void
    {
        $metadata                   = new ClassMetadata(RuntimeEnvVariable::class);
        $metadata->table['name']    = 'old_name';
        $metadata->table['options'] = ['charset' => 'utf8mb4'];
        $args                       = new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class));

        (new RuntimeEnvMetadataListener('custom_runtime_variables'))->loadClassMetadata($args);

        self::assertSame('custom_runtime_variables', $metadata->table['name']);
        self::assertSame(['charset' => 'utf8mb4'], $metadata->table['options']);
    }
}
