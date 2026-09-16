<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoRuntimeEnvBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoRuntimeEnvBundle';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new RuntimeEnvExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
