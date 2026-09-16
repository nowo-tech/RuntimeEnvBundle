<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvWriterTest extends TestCase
{
    /** @var MockObject&RuntimeEnvVariableRepositoryInterface */
    private RuntimeEnvVariableRepositoryInterface $repository;

    private RuntimeEnvBag $bag;

    private RuntimeEnvWriter $writer;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $this->bag        = new RuntimeEnvBag($this->repository, true);
        $this->writer     = new RuntimeEnvWriter($this->repository, $this->bag);
    }

    public function testCreateRejectsInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->writer->create('lowercase', 'x');
    }

    public function testCreateRejectsDuplicate(): void
    {
        $this->repository->method('findOneByName')->willReturn(new RuntimeEnvVariable('API_KEY', 'old'));
        $this->expectException(InvalidArgumentException::class);
        $this->writer->create('API_KEY', 'new');
    }

    public function testCreatePersistsAndClearsCache(): void
    {
        $this->repository->method('findOneByName')->willReturn(null);
        $this->repository->expects(self::once())->method('save')->with(self::isInstanceOf(RuntimeEnvVariable::class));

        $created = $this->writer->create('API_KEY', 'secret', 'desc', true);

        self::assertSame('API_KEY', $created->getName());
        self::assertSame('secret', $created->getValue());
        self::assertSame('desc', $created->getDescription());
        self::assertTrue($created->isEnabled());
    }

    public function testUpdatePersistsMutatedVariable(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'old', 'Old description', true);

        $this->repository->expects(self::once())
            ->method('save')
            ->with($variable);

        $updated = $this->writer->update($variable, 'new', 'New description', false);

        self::assertSame($variable, $updated);
        self::assertSame('new', $variable->getValue());
        self::assertSame('New description', $variable->getDescription());
        self::assertFalse($variable->isEnabled());
    }

    public function testRenameRejectsInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer->rename(new RuntimeEnvVariable('APP_KEY', 'old'), 'invalid-name');
    }

    public function testRenameRejectsDuplicateName(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'old');
        $this->repository->method('findOneByName')->willReturn(new RuntimeEnvVariable('API_TOKEN', 'existing'));

        $this->expectException(InvalidArgumentException::class);

        $this->writer->rename($variable, 'API_TOKEN');
    }

    public function testRenamePersistsWhenNameChanges(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'old');
        $this->repository->method('findOneByName')->willReturn(null);
        $this->repository->expects(self::once())
            ->method('save')
            ->with($variable);

        $renamed = $this->writer->rename($variable, 'API_TOKEN');

        self::assertSame($variable, $renamed);
        self::assertSame('API_TOKEN', $variable->getName());
    }

    public function testRenameIsNoOpWhenNameDoesNotChange(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'old');
        $this->repository->expects(self::never())->method('findOneByName');
        $this->repository->expects(self::never())->method('save');

        $renamed = $this->writer->rename($variable, 'APP_KEY');

        self::assertSame($variable, $renamed);
        self::assertSame('APP_KEY', $variable->getName());
    }

    public function testDeleteRemovesAndClearsCache(): void
    {
        $variable = new RuntimeEnvVariable('X', '1');
        $this->repository->expects(self::once())->method('remove')->with($variable);

        $this->writer->delete($variable);
    }
}
