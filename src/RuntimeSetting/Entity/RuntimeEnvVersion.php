<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.6: version token of a runtime-setting namespace (nowo-tech/runtime-setting-contract). One row per namespace; the
 * variables table is the `env` namespace. The token changes with every committed write of the namespace and is never
 * reused (counter plus random part), so a value cached under a rolled-back token never matches again.
 *
 * Mapped only when `runtime_setting.enabled` is true (its own mapping directory); the table is `{prefix}_versions`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'runtime_env_versions')]
class RuntimeEnvVersion
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'namespace', type: 'string', length: 64)]
        private string $namespace,
        #[ORM\Column(type: 'string', length: 128)]
        private string $token,
    ) {
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->token = $token;

        return $this;
    }
}
