<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\RuntimeSetting;

use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;

use function str_starts_with;
use function strrev;
use function substr;

/**
 * Reversible stand-in for DoctrineEncryptBundle in the kernel tests (no key files).
 */
final class TestCipher implements RuntimeEnvValueCipherInterface
{
    public function encrypt(string $plain): string
    {
        return 'enc:' . strrev($plain);
    }

    public function decrypt(string $stored): string
    {
        return str_starts_with($stored, 'enc:') ? strrev(substr($stored, 4)) : $stored;
    }
}
