<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvTransaction;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Psr\Clock\ClockInterface;

use function preg_match;
use function sprintf;

/**
 * Persists runtime env variables and invalidates {@see RuntimeEnvBag} cache.
 *
 * 1.5: writes go to `database.entity_manager` only; with a {@see RuntimeEnvTransaction} each write records a
 * `RuntimeEnvVariableChangedEvent` (name only, never the value) dispatched after the commit, and with
 * `database.transaction: host` the writer does not flush (the host's use case commits).
 */
final class RuntimeEnvWriter implements RuntimeEnvWriterInterface
{
    public const NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly RuntimeEnvBag $bag,
        private readonly ?RuntimeEnvTransaction $transaction = null,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function create(string $name, string $value, ?string $description = null, bool $enabled = true): RuntimeEnvVariable
    {
        $this->assertValidName($name);
        if ($this->repository->findOneByName($name) instanceof RuntimeEnvVariable) {
            throw new InvalidArgumentException(sprintf('Runtime env variable "%s" already exists.', $name));
        }

        $variable = new RuntimeEnvVariable($name, $value, $description, $enabled);
        $this->save($variable, RuntimeEnvVariableChangedEvent::CREATED);

        return $variable;
    }

    public function update(RuntimeEnvVariable $variable, string $value, ?string $description, bool $enabled): RuntimeEnvVariable
    {
        $variable
            ->setValue($value)
            ->setDescription($description)
            ->setEnabled($enabled);
        $this->save($variable, RuntimeEnvVariableChangedEvent::UPDATED);

        return $variable;
    }

    public function rename(RuntimeEnvVariable $variable, string $newName): RuntimeEnvVariable
    {
        $this->assertValidName($newName);
        $previousName = $variable->getName();
        if ($newName !== $previousName) {
            $existing = $this->repository->findOneByName($newName);
            if ($existing instanceof RuntimeEnvVariable) {
                throw new InvalidArgumentException(sprintf('Runtime env variable "%s" already exists.', $newName));
            }
            $variable->setName($newName);
            $this->save($variable, RuntimeEnvVariableChangedEvent::RENAMED, $previousName);
        }

        return $variable;
    }

    public function delete(RuntimeEnvVariable $variable): void
    {
        $flush = $this->flushes();
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->repository->remove($variable, $flush);
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->bag->clearRuntimeCache();
        $this->transaction?->record($flush, new RuntimeEnvVariableChangedEvent($variable->getName(), RuntimeEnvVariableChangedEvent::DELETED, $this->now()));
    }

    public function createVariable(string $name, string $value, ?string $description = null, bool $enabled = true): void
    {
        $this->create($name, $value, $description, $enabled);
    }

    public function updateByName(string $name, string $value, ?string $description, bool $enabled): void
    {
        $this->update($this->require($name), $value, $description, $enabled);
    }

    public function renameByName(string $name, string $newName): void
    {
        $this->rename($this->require($name), $newName);
    }

    public function deleteByName(string $name): void
    {
        $this->delete($this->require($name));
    }

    private function save(RuntimeEnvVariable $variable, string $action, ?string $previousName = null): void
    {
        $flush = $this->flushes();
        $this->repository->save($variable, $flush);
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->bag->clearRuntimeCache();
        $this->transaction?->record($flush, new RuntimeEnvVariableChangedEvent($variable->getName(), $action, $this->now(), $previousName));
    }

    private function flushes(): bool
    {
        return $this->transaction?->flushesItself() ?? true;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function require(string $name): RuntimeEnvVariable
    {
        $variable = $this->repository->findOneByName($name);
        if (!$variable instanceof RuntimeEnvVariable) {
            throw new InvalidArgumentException(sprintf('Runtime env variable "%s" does not exist.', $name));
        }

        return $variable;
    }

    private function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Variable name must match ^[A-Z][A-Z0-9_]*$ (e.g. MY_API_TOKEN).');
        }
    }
}
