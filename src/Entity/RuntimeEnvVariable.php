<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\RuntimeEnvBundle\Concurrency\RowVersionListener;
use Nowo\RuntimeEnvBundle\Concurrency\RuntimeEnvRowVersions;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;

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

    /**
     * 1.9 (REQ-RUNTIME-003 rule 4): the optimistic row version of the manage page's edit form.
     *
     * The ORM never writes it (`insertable` / `updatable` false: the database default gives 1, so it can never go
     * backwards). Every change of the row advances it with one atomic `UPDATE … SET row_version = row_version + 1`
     * ({@see RuntimeEnvRowVersions}, {@see RowVersionListener}, the runtime-setting store). The loaded value may lag
     * behind the database; the edit page reads the current one with {@see RuntimeEnvRowVersions::current()}.
     */
    #[ORM\Column(name: 'row_version', type: 'integer', insertable: false, updatable: false, options: ['default' => 1])]
    private int $rowVersion = 1;

    /**
     * 1.6 (runtime_setting.enabled only): the previous value of a rotation, as stored (ciphertext with the
     * DoctrineEncrypt marker, written by {@see RuntimeEnvSettingStore}). Mapped by {@see RuntimeEnvMetadataListener}
     * only when the feature is on, so hosts that do not use it need no migration.
     */
    private ?string $previousValue = null;

    /** 1.6: when {@see $previousValue} stops being accepted (mapped only when runtime_setting.enabled). */
    private ?DateTimeImmutable $previousExpiresAt = null;

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

    /**
     * 1.9: the row version as loaded (it may lag behind the database, see {@see $rowVersion}).
     */
    public function getRowVersion(): int
    {
        return $this->rowVersion;
    }

    /**
     * 1.6: the previous value of a rotation as stored (ciphertext), or null.
     */
    public function getPreviousValue(): ?string
    {
        return $this->previousValue;
    }

    public function getPreviousExpiresAt(): ?DateTimeImmutable
    {
        return $this->previousExpiresAt;
    }

    /**
     * @internal 1.6: Doctrine hydrates the rotation columns (written by RuntimeEnvSettingStore through DBAL); this setter
     *           serves tests and static analysis
     */
    public function setPrevious(?string $previousValue, ?DateTimeImmutable $previousExpiresAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->previousValue = $previousValue;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->previousExpiresAt = $previousExpiresAt;

        return $this;
    }

    /**
     * 1.6: forgets the previous value of a rotation (a plain write replaces the value; the old one is no longer
     * accepted).
     */
    public function clearPrevious(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->previousValue = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->previousExpiresAt = null;

        return $this;
    }
}
