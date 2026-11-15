<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;

/**
 * 1.6: {@see RuntimeEnvValueCipherInterface} over DoctrineEncryptBundle's default profile, the one the entity's
 * `#[Encrypted]` value column uses: ciphertext plus the `<ENC>` marker. Reading is lenient like the ORM listener (a row
 * stored before encryption was enabled reads as plaintext).
 */
final readonly class DoctrineEncryptValueCipher implements RuntimeEnvValueCipherInterface
{
    public function __construct(
        private EncryptUtil $encryptUtil,
    ) {
    }

    public function encrypt(string $plain): string
    {
        return (string) $this->encryptUtil->encrypt($plain);
    }

    public function decrypt(string $stored): string
    {
        return (string) $this->encryptUtil->decrypt($stored);
    }
}
