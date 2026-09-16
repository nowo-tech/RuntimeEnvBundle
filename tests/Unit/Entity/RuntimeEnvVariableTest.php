<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvVariableTest extends TestCase
{
    public function testConstructorAndGettersExposeInitialState(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret', 'Primary key', false);

        self::assertNull($variable->getId());
        self::assertSame('APP_KEY', $variable->getName());
        self::assertSame('secret', $variable->getValue());
        self::assertSame('Primary key', $variable->getDescription());
        self::assertFalse($variable->isEnabled());
        self::assertInstanceOf(DateTimeImmutable::class, $variable->getUpdatedAt());
    }

    public function testSettersReturnSelfAndRefreshUpdatedAt(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $initial  = $variable->getUpdatedAt();

        self::assertSame($variable, $variable->setId(15));
        self::assertSame(15, $variable->getId());

        self::assertSame($variable, $variable->setName('APP_TOKEN'));
        $afterName = $variable->getUpdatedAt();
        self::assertSame('APP_TOKEN', $variable->getName());
        self::assertNotSame($initial, $afterName);

        self::assertSame($variable, $variable->setValue('rotated'));
        $afterValue = $variable->getUpdatedAt();
        self::assertSame('rotated', $variable->getValue());
        self::assertNotSame($afterName, $afterValue);

        self::assertSame($variable, $variable->setDescription('Rotated token'));
        $afterDescription = $variable->getUpdatedAt();
        self::assertSame('Rotated token', $variable->getDescription());
        self::assertNotSame($afterValue, $afterDescription);

        self::assertSame($variable, $variable->setEnabled(false));
        self::assertFalse($variable->isEnabled());
        self::assertNotSame($afterDescription, $variable->getUpdatedAt());
    }
}
