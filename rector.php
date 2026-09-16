<?php

declare(strict_types=1);

/**
 * Rector configuration for Runtime Env Bundle.
 *
 * Ensures PHP 8.1+ and Symfony 6|7|8 compatibility; applies dead code, code quality,
 * and type declaration rules. Processes src/ and tests/.
 *
 * @see https://getrector.com/documentation
 */
use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\Class_\TypedPropertyFromCreateMockAssignRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
        // Keep MockObject&Interface intersection types on test properties
        TypedPropertyFromCreateMockAssignRector::class,
        // Keep dirname(__DIR__) . '/Entity' (realpath form matches tests / Doctrine mapping)
        DirnameDirConcatStringToDirectStringPathRector::class,
    ]);
