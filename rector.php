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
use Rector\Config\RectorConfig;
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
    ]);
