<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\RuntimeEnvEntityManagerPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoRuntimeEnvBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoRuntimeEnvBundle';

    /** FormKit profile (nowo_form_kit.profiles.runtime_env) used by every bundle form type (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'runtime_env';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new UnauthenticatedAccessPass());
        // Before DoctrineBundle's MiddlewaresPass: the after-commit middleware on the bundle connection only (1.5).
        $container->addCompilerPass(new AfterCommitMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        // 1.5: database.entity_manager is real (fail fast, then the mapping on that manager only).
        $container->addCompilerPass(new RuntimeEnvEntityManagerPass(checkOnly: true), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new RuntimeEnvEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new RuntimeEnvExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
