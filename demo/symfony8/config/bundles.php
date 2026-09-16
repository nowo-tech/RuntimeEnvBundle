<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\HotReloadBundle\NowoHotReloadBundle;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

return [
    FrameworkBundle::class           => ['all' => true],
    SecurityBundle::class            => ['all' => true],
    DoctrineBundle::class            => ['all' => true],
    TwigBundle::class                => ['all' => true],
    TwigExtraBundle::class           => ['all' => true],
    NowoDoctrineEncryptBundle::class => ['all' => true],
    NowoUiKitBundle::class           => ['all' => true],
    NowoRuntimeEnvBundle::class      => ['all' => true],
    DebugBundle::class               => ['dev' => true],
    WebProfilerBundle::class         => ['dev' => true, 'test' => true],
    NowoHotReloadBundle::class       => ['dev' => true, 'test' => true],
    NowoTwigInspectorBundle::class   => ['dev' => true, 'test' => true],
];
