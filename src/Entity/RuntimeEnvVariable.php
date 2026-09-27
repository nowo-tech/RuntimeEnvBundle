<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

/**
 * A single runtime environment variable persisted in the database.
 *
 * The {@see $value} column is encrypted at rest via DoctrineEncryptBundle (Halite/Defuse).
 */
#[ORM\Entity]
#[ORM\Table(name: 'runtime_env_variables')]
#[ORM\UniqueConstraint(name: 'uniq_runtime_env_name', columns: ['name'])]
class RuntimeEnvVariable
{
    /** Doctrine assigns this via reflection after persist/hydrate. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: 'string', length: 128)]
        private string $name,
        #[ORM\Column(type: 'text')]
        #[Encrypted]
        private string $value = '',
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $description = null,
        #[ORM\Column(type: 'boolean')]
        private bool $enabled = true,
    ) {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @internal doctrine hydrates {@see $id} via reflection; this setter satisfies static analysis / tests
     */
    public function setId(int $id): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->id = $id;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = $value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->enabled = $enabled;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
