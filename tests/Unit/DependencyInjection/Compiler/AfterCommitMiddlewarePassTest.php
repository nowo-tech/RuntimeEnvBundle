<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\RuntimeEnvEntityManagerPass;
use Nowo\RuntimeEnvBundle\Persistence\AfterCommitMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(AfterCommitMiddlewarePass::class)]
final class AfterCommitMiddlewarePassTest extends TestCase
{
    #[Test]
    public function theMiddlewareFollowsTheBundleConnection(): void
    {
        $container = new ContainerBuilder();
        (new AfterCommitMiddlewarePass())->process($container);
        $container->register(AfterCommitMiddleware::class)->addTag('doctrine.middleware');
        $container->setParameter(RuntimeEnvEntityManagerPass::PARAMETER, 'runtime_env');
        $container->register('doctrine.orm.runtime_env_entity_manager')->setArguments([new Reference('doctrine.dbal.pages_connection')]);
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'pages']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));

        // No option: the default manager's connection, else the default connection.
        $container->setParameter(RuntimeEnvEntityManagerPass::PARAMETER, null);
        $container->setParameter('doctrine.default_connection', 'main');
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'main']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));

        $bare = new ContainerBuilder();
        $bare->register(AfterCommitMiddleware::class)->addTag('doctrine.middleware');
        (new AfterCommitMiddlewarePass())->process($bare);
        self::assertSame([['connection' => 'default']], $bare->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));
    }
}
