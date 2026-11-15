<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

/**
 * 1.6: encrypts and decrypts the stored values of {@see RuntimeEnvSettingStore}, which writes through DBAL (it never
 * flushes an entity manager). The default implementation is {@see DoctrineEncryptValueCipher}: the same format as the
 * `#[Encrypted]` value column of the entity, so ORM and DBAL read each other's rows.
 */
interface RuntimeEnvValueCipherInterface
{
    public function encrypt(string $plain): string;

    public function decrypt(string $stored): string;
}
