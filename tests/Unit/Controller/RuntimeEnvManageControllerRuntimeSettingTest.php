<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvAdminSettings;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Tests\Support\RuntimeSettingDatabaseTrait;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

use function array_key_exists;
use function str_repeat;

/**
 * 1.6: admin edits of a registered runtime setting go through RuntimeSettingUpdater (validator, audit actor).
 */
final class RuntimeEnvManageControllerRuntimeSettingTest extends TestCase
{
    use RuntimeSettingDatabaseTrait;

    private Connection $connection;

    private RuntimeSettingResolver $resolver;

    private RuntimeSettingDefinition $definition;

    private RuntimeEnvAdminSettings $settings;

    /** @var list<FormError> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->connection = $this->database();
        $store            = $this->store($this->connection);
        $this->definition = new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new SingleLineString(64));
        $registry         = new RuntimeSettingRegistry([$this->definition]);
        $this->resolver   = new RuntimeSettingResolver($store, versionTtl: 0);
        $this->settings   = new RuntimeEnvAdminSettings($registry, new RuntimeSettingUpdater($registry, $store, $this->resolver, new RuntimeValueFingerprinter(str_repeat('k', 32))), $store);
    }

    public function testNewWritesARegisteredSettingThroughTheUpdater(): void
    {
        $response = $this->controller($this->form(static function (RuntimeEnvVariable $variable): void {
            $variable->setName('API_TOKEN')->setValue('REDACTED-TEST-A')->setDescription('Token');
        }))->new($this->request('POST'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('REDACTED-TEST-A', $this->resolver->get($this->definition));
    }

    public function testNewShowsTheValidatorsRefusalOnTheValueField(): void
    {
        $response = $this->controller($this->form(static function (RuntimeEnvVariable $variable): void {
            $variable->setName('API_TOKEN')->setValue("two\nlines");
        }))->new($this->request('POST'));

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('refused by the validator', $this->errors[0]->getMessage(), 'a secret gets a generic reason (QS-L-2)');
        self::assertStringNotContainsString('two', $this->errors[0]->getMessage());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
    }

    public function testEditTypedValueKeptValueAndRefusedClear(): void
    {
        $this->settings->create('API_TOKEN', 'REDACTED-TEST-A', null, true, 'user:seed');
        $variable = (new RuntimeEnvVariable('API_TOKEN', 'REDACTED-TEST-A'))->setId(7);

        $typed = $this->controller($this->form(null, 'REDACTED-TEST-B', false), $variable)->edit($this->request('POST'), 7);
        self::assertInstanceOf(RedirectResponse::class, $typed);
        $this->resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-B', $this->resolver->get($this->definition));

        $kept = $this->controller($this->form(null, '', false), $variable)->edit($this->request('POST'), 7);
        self::assertInstanceOf(RedirectResponse::class, $kept);
        $this->resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-B', $this->resolver->get($this->definition));

        $cleared = $this->controller($this->form(null, '', true), $variable)->edit($this->request('POST'), 7);
        self::assertSame(422, $cleared->getStatusCode(), 'a secret cannot be cleared');
        self::assertCount(1, $this->errors);
    }

    public function testTheSignedInUserIsTheAuditActor(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('jane', null), 'main'));

        $response = $this->controller($this->form(static function (RuntimeEnvVariable $variable): void {
            $variable->setName('API_TOKEN')->setValue('REDACTED-TEST-A');
        }), null, $tokens)->new($this->request('POST'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('REDACTED-TEST-A', $this->resolver->get($this->definition));
        self::assertSame('env', RuntimeSettingId::fromString('API_TOKEN')->namespace);
    }

    /**
     * @param (callable(RuntimeEnvVariable): void)|null $fill
     */
    private function form(?callable $fill, ?string $typed = null, bool $clear = false): callable
    {
        return function (string $type, mixed $data) use ($fill, $typed, $clear): FormInterface {
            if ($fill !== null && $data instanceof RuntimeEnvVariable) {
                $fill($data);
            }
            $value = $this->createMock(FormInterface::class);
            $value->method('getData')->willReturn($typed);
            $value->method('addError')->willReturnCallback(function (FormError $error) use ($value): FormInterface {
                $this->errors[] = $error;

                return $value;
            });
            $clearField = $this->createMock(FormInterface::class);
            $clearField->method('getData')->willReturn($clear);

            $form = $this->createMock(FormInterface::class);
            $form->method('handleRequest')->willReturnSelf();
            $form->method('isSubmitted')->willReturn(true);
            $form->method('isValid')->willReturn(true);
            $form->method('createView')->willReturn(new FormView());
            $form->method('get')->willReturnCallback(static fn (string $name): FormInterface => $name === 'value' ? $value : $clearField);

            return $form;
        };
    }

    private function controller(callable $forms, ?RuntimeEnvVariable $variable = null, ?TokenStorage $tokens = null): RuntimeEnvManageController
    {
        $repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $repository->method('find')->willReturn($variable);
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturnCallback($forms);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/runtime-env');
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('page');
        $requestStack = new RequestStack();
        $requestStack->push($this->request('GET'));

        $controller = new RuntimeEnvManageController($repository, new RuntimeEnvWriter($repository, new RuntimeEnvBag($repository, true)), '/admin/runtime-env', 50, new RuntimeEnvPageFactory($router, new RuntimeEnvPages()), null, $this->settings);
        $services   = ['form.factory' => $factory, 'twig' => $twig, 'router' => $router, 'request_stack' => $requestStack];
        if ($tokens instanceof TokenStorage) {
            $services['security.token_storage'] = $tokens;
        }
        $controller->setContainer(new class($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): mixed
            {
                return $this->has($id) ? $this->services[$id] : throw new RuntimeException('Missing ' . $id);
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        });

        return $controller;
    }

    private function request(string $method): Request
    {
        $request = Request::create('/admin/runtime-env/new', $method);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
