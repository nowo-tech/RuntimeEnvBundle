<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\RuntimeSettingPortsPass;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\RuntimeSetting\DoctrineEncryptValueCipher;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Entity\RuntimeEnvVersion;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvAdminSettings;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvVersionBumper;
use Nowo\RuntimeEnvBundle\Tests\Support\RuntimeSettingDatabaseTrait;
use Nowo\RuntimeEnvBundle\Transaction\StorageFailureListener;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function str_repeat;
use function strlen;

/**
 * 1.6: cipher, version bumper, admin path, 503/409 listener, ports pass, metadata mapping, entity accessors.
 */
final class RuntimeSettingPiecesTest extends TestCase
{
    use RuntimeSettingDatabaseTrait;

    public function testDoctrineEncryptCipherUsesTheEntityFormat(): void
    {
        $encryptor = new class implements EncryptorInterface {
            public function encrypt(string $data): string
            {
                return 'X' . $data;
            }

            public function decrypt(string $data): string
            {
                return substr($data, 1);
            }
        };
        $cipher = new DoctrineEncryptValueCipher(new EncryptUtil(new EncryptorRegistry(['default' => $encryptor])));

        self::assertSame('Xsecret' . EncryptUtil::ENCRYPTION_MARKER, $cipher->encrypt('secret'));
        self::assertSame('secret', $cipher->decrypt('Xsecret' . EncryptUtil::ENCRYPTION_MARKER));
        self::assertSame('plain', $cipher->decrypt('plain'), 'a row stored before encryption reads as plaintext');
        self::assertSame('', $cipher->encrypt(''));
    }

    public function testTheBumperSchedulesANewTokenInTheCallersUnitOfWork(): void
    {
        $existing = new RuntimeEnvVersion('env', 'old');
        $manager  = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('find')->willReturnOnConsecutiveCalls($existing, null);
        $manager->expects(self::once())->method('persist')->with(self::callback(static fn (RuntimeEnvVersion $v): bool => $v->getNamespace() === 'env' && strlen($v->getToken()) === 32));
        $manager->expects(self::never())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $bumper = new RuntimeEnvVersionBumper($registry, 'default');

        $bumper->bump();
        self::assertNotSame('old', $existing->getToken());
        $bumper->bump();
        self::assertNotSame(RuntimeEnvVersionBumper::newToken(), RuntimeEnvVersionBumper::newToken());
    }

    public function testTheBumperRecreatesAClosedOwnedManagerAndRefusesANonOrmOne(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('find')->willReturn(null);
        $fresh->expects(self::once())->method('persist');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($closed);
        $registry->expects(self::once())->method('resetManager')->with('runtime_env')->willReturn($fresh);
        (new RuntimeEnvVersionBumper($registry, 'runtime_env', true))->bump();

        $other = $this->createMock(ManagerRegistry::class);
        $other->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $this->expectException(LogicException::class);
        (new RuntimeEnvVersionBumper($other, 'odm', false))->bump();
    }

    public function testTheAdminPathUsesTheUpdaterRules(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $registry   = new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new SingleLineString(64)),
            new RuntimeSettingDefinition('ENV_ONLY', RuntimeSettingType::String),
        ]);
        $resolver   = new RuntimeSettingResolver($store, versionTtl: 0);
        $admin      = new RuntimeEnvAdminSettings($registry, new RuntimeSettingUpdater($registry, $store, $resolver, new RuntimeValueFingerprinter(str_repeat('k', 32))), $store);
        $definition = $registry->get('API_TOKEN');

        self::assertTrue($admin->manages('API_TOKEN'));
        self::assertFalse($admin->manages('ENV_ONLY'), 'source env: not writable at runtime');
        self::assertFalse($admin->manages('FREE_FORM'));

        try {
            $admin->create('API_TOKEN', "two\nlines", 'Token', true, 'user:admin');
            self::fail('Expected the validator to refuse.');
        } catch (InvalidRuntimeSettingValue) {
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'), 'nothing written');

        $admin->create('API_TOKEN', 'REDACTED-TEST-A', 'Token', false, 'user:admin');
        self::assertNull($resolver->get($definition), 'created disabled');
        self::assertSame('Token', $connection->fetchOne('SELECT description FROM runtime_env_variables'));

        $admin->update('API_TOKEN', null, true, false, 'REDACTED-TEST-A', 'Token', 'user:admin');
        $resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-A', $resolver->get($definition), 're-enabled with the stored value, validated again');

        $admin->update('API_TOKEN', 'REDACTED-TEST-B', true, true, 'REDACTED-TEST-A', 'New', 'user:admin');
        $resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-B', $resolver->get($definition));

        $admin->update('API_TOKEN', null, true, true, 'REDACTED-TEST-B', 'Kept', 'user:admin');
        $resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-B', $resolver->get($definition), 'no value typed: kept');
        self::assertSame('Kept', $connection->fetchOne('SELECT description FROM runtime_env_variables'));

        $admin->update('API_TOKEN', null, false, true, 'REDACTED-TEST-B', 'Kept', 'user:admin');
        $resolver->clearRuntimeCache();
        self::assertNull($resolver->get($definition));

        $this->expectException(InvalidRuntimeSettingValue::class);
        $admin->update('API_TOKEN', '', true, false, 'REDACTED-TEST-B', null, 'user:admin');
    }

    public function testAdminActor(): void
    {
        self::assertSame('user:anonymous', RuntimeEnvAdminSettings::actor(null));
        self::assertSame('user:anonymous', RuntimeEnvAdminSettings::actor(''));
        self::assertSame('user:jane_doe@example.test', RuntimeEnvAdminSettings::actor('jane doe@example.test'));
        self::assertSame(5 + 128, strlen(RuntimeEnvAdminSettings::actor(str_repeat('x', 300))));
    }

    public function testStorageFailuresOnBundleRoutesAnswer503Or409(): void
    {
        $listener = new StorageFailureListener();

        $transient = $this->event(TransientStorageFailure::fromPrevious(new RuntimeException('deadlock'), 7));
        $listener->onException($transient);
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $transient->getThrowable());
        self::assertSame(7, $transient->getThrowable()->getHeaders()['Retry-After']);

        $hint = $this->event(new RuntimeException('wrapped', 0, TransientStorageFailure::fromPrevious(new RuntimeException('x'))));
        $listener->onException($hint);
        self::assertSame(1, $hint->getThrowable() instanceof ServiceUnavailableHttpException ? $hint->getThrowable()->getHeaders()['Retry-After'] : null);

        $retryable = $this->event($this->createStub(DeadlockException::class));
        $listener->onException($retryable);
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $retryable->getThrowable());

        $conflict = $this->event(new RuntimeException('x', 0, $this->createStub(UniqueConstraintViolationException::class)));
        $listener->onException($conflict);
        self::assertInstanceOf(ConflictHttpException::class, $conflict->getThrowable());

        $other = $this->event($original = new RuntimeException('other'));
        $listener->onException($other);
        self::assertSame($original, $other->getThrowable());

        $host = $this->event($failure = TransientStorageFailure::fromPrevious(new RuntimeException('x')), 'App\\Controller\\Home::index');
        $listener->onException($host);
        self::assertSame($failure, $host->getThrowable(), 'host routes keep their own handling');
    }

    public function testThePortsPassBindsOnlyWhenEnabledAndAbsent(): void
    {
        $off = new ContainerBuilder();
        $off->setParameter('nowo_runtime_env.runtime_setting.enabled', false);
        (new RuntimeSettingPortsPass())->process($off);
        self::assertFalse($off->has(RuntimeValueSourceInterface::class));

        $on = new ContainerBuilder();
        $on->setParameter('nowo_runtime_env.runtime_setting.enabled', true);
        $on->register('host.writer', stdClass::class);
        $on->setAlias(RuntimeSettingWriterInterface::class, 'host.writer');
        (new RuntimeSettingPortsPass())->process($on);
        self::assertSame(RuntimeEnvSettingStore::class, (string) $on->getAlias(RuntimeValueSourceInterface::class));
        self::assertSame('host.writer', (string) $on->getAlias(RuntimeSettingWriterInterface::class), 'kept');
        self::assertFalse($on->hasDefinition(RuntimeEnvAdminSettings::class), 'no updater: no admin path');

        $full = new ContainerBuilder();
        $full->setParameter('nowo_runtime_env.runtime_setting.enabled', true);
        $full->register(RuntimeSettingUpdater::class, stdClass::class);
        $full->register(RuntimeSettingRegistry::class, stdClass::class);
        $full->setDefinition(RuntimeEnvManageController::class, new Definition(RuntimeEnvManageController::class));
        (new RuntimeSettingPortsPass())->process($full);
        self::assertTrue($full->hasDefinition(RuntimeEnvAdminSettings::class));
        self::assertSame(RuntimeEnvAdminSettings::class, (string) $full->getDefinition(RuntimeEnvManageController::class)->getArgument('$settings'));

        $noController = new ContainerBuilder();
        $noController->setParameter('nowo_runtime_env.runtime_setting.enabled', true);
        $noController->register(RuntimeSettingUpdater::class, stdClass::class);
        $noController->register(RuntimeSettingRegistry::class, stdClass::class);
        (new RuntimeSettingPortsPass())->process($noController);
        self::assertTrue($noController->hasDefinition(RuntimeEnvAdminSettings::class));

        $none = new ContainerBuilder();
        (new RuntimeSettingPortsPass())->process($none);
        self::assertFalse($none->has(RuntimeValueSourceInterface::class));
    }

    public function testTheRotationColumnsAreMappedOnlyWhenEnabled(): void
    {
        $off                = new ClassMetadata(RuntimeEnvVariable::class);
        $off->table['name'] = 'runtime_env_variables';
        (new RuntimeEnvMetadataListener('runtime_env_variables'))->loadClassMetadata($this->metadataArgs($off));
        self::assertFalse($off->hasField('previousValue'));

        $on                = new ClassMetadata(RuntimeEnvVariable::class);
        $on->table['name'] = 'runtime_env_variables';
        $listener          = new RuntimeEnvMetadataListener('custom_variables', true, 'custom_versions');
        $listener->loadClassMetadata($this->metadataArgs($on));
        $listener->loadClassMetadata($this->metadataArgs($on));
        self::assertSame('previous_value', $on->getColumnName('previousValue'));
        self::assertSame('previous_expires_at', $on->getColumnName('previousExpiresAt'));
        self::assertTrue($on->isNullable('previousValue'));
        self::assertSame(['columns' => ['previous_expires_at']], $on->table['indexes']['idx_custom_variables_prev_exp']);

        $version                = new ClassMetadata(RuntimeEnvVersion::class);
        $version->table['name'] = 'runtime_env_versions';
        $listener->loadClassMetadata($this->metadataArgs($version));
        self::assertSame('custom_versions', $version->table['name']);

        $versionWithoutTable                = new ClassMetadata(RuntimeEnvVersion::class);
        $versionWithoutTable->table['name'] = 'runtime_env_versions';
        (new RuntimeEnvMetadataListener('runtime_env_variables'))->loadClassMetadata($this->metadataArgs($versionWithoutTable));
        self::assertSame('runtime_env_versions', $versionWithoutTable->table['name']);
    }

    public function testEntityRotationAccessors(): void
    {
        $expires  = new DateTimeImmutable('2026-11-16 13:00:00');
        $variable = (new RuntimeEnvVariable('API_TOKEN', 'x'))->setPrevious('enc:old', $expires);

        self::assertSame('enc:old', $variable->getPreviousValue());
        self::assertSame($expires, $variable->getPreviousExpiresAt());
        $variable->clearPrevious();
        self::assertNull($variable->getPreviousValue());
        self::assertNull($variable->getPreviousExpiresAt());
        self::assertSame('env', RuntimeSettingId::fromString('API_TOKEN')->namespace);
        self::assertSame(3600, (new DateTimeImmutable('@0'))->add(new DateInterval('PT1H'))->getTimestamp());
    }

    private function metadataArgs(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class));
    }

    private function event(Throwable $failure, string $controller = 'Nowo\\RuntimeEnvBundle\\Controller\\RuntimeEnvManageController::edit'): ExceptionEvent
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
