<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Nowo\RuntimeEnvBundle\Concurrency\RuntimeEnvRowVersions;
use Nowo\RuntimeEnvBundle\Concurrency\StaleEditException;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvAdminSettings;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Tests\Support\RuntimeSettingDatabaseTrait;
use Nowo\RuntimeEnvBundle\Transaction\StorageFailureListener;
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

    /** Request stack of the last controller (its session holds the flashes). */
    private RequestStack $stack;

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
     * 1.9 (REQ-RUNTIME-003 rule 4): a registered setting edited on a page opened before another save is refused: 409,
     * `X-Error-Code: RUNTIME_ENV_STALE_EDIT` (no conflict code), a flash, nothing written.
     */
    public function testASettingEditFromAStalePageIsRefused(): void
    {
        $this->settings->create('API_TOKEN', 'REDACTED-TEST-A', null, true, 'user:seed');
        $id      = $this->idOf('API_TOKEN');
        $current = $this->rowVersion($id);

        $response = $this->controller($this->form(null, 'REDACTED-TEST-B', false, (string) ($current - 1)), $this->variable($id), null, $this->rowVersions())->edit($this->request('POST'), $id);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(StaleEditException::ERROR_CODE, $response->headers->get(StorageFailureListener::ERROR_CODE_HEADER));
        self::assertFalse($response->headers->has(StorageFailureListener::CODE_HEADER));
        self::assertSame([StaleEditException::MESSAGE], $this->stack->getSession()->getFlashBag()->peek('error'));
        $this->resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-A', $this->resolver->get($this->definition));
        self::assertSame($current, $this->rowVersion($id));
    }

    /**
     * 1.9: the current page saves; the claim and the store's writes advance the version.
     */
    public function testASettingEditFromTheCurrentPageIsSaved(): void
    {
        $this->settings->create('API_TOKEN', 'REDACTED-TEST-A', null, true, 'user:seed');
        $id      = $this->idOf('API_TOKEN');
        $current = $this->rowVersion($id);

        $response = $this->controller($this->form(null, 'REDACTED-TEST-B', false, (string) $current), $this->variable($id), null, $this->rowVersions())->edit($this->request('POST'), $id);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $this->resolver->clearRuntimeCache();
        self::assertSame('REDACTED-TEST-B', $this->resolver->get($this->definition));
        self::assertSame($current + 3, $this->rowVersion($id), 'claim, value write, description write');
    }

    /**
     * 1.9: a value the validator refuses writes nothing; the claim advanced the version, so the form carries the new
     * one and the corrected value can be saved from the same page.
     */
    public function testARefusedValueKeepsTheClaimedVersionOnTheForm(): void
    {
        $this->settings->create('API_TOKEN', 'REDACTED-TEST-A', null, true, 'user:seed');
        $id                                                        = $this->idOf('API_TOKEN');
        $current                                                   = $this->rowVersion($id);
        $view                                                      = new FormView();
        $view->children[RuntimeEnvVariableType::ROW_VERSION_FIELD] = new FormView($view);

        $response = $this->controller($this->form(null, "two\nlines", false, (string) $current, $view), $this->variable($id), null, $this->rowVersions())->edit($this->request('POST'), $id);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(1, $this->errors);
        self::assertSame((string) ($current + 1), $view[RuntimeEnvVariableType::ROW_VERSION_FIELD]->vars['value']);
        self::assertSame($current + 1, $this->rowVersion($id));
    }

    /**
     * 1.9: a plain variable (not a registered setting) edited on a stale page: 409, the writer never runs.
     */
    public function testAPlainEditFromAStalePageIsRefusedAndTheCurrentOneSaved(): void
    {
        $this->insertVariable($this->connection, 'PLAIN', 'p0');
        $id      = $this->idOf('PLAIN');
        $current = $this->rowVersion($id);

        $stale = $this->controller($this->form(null, 'p1', false, 'junk'), (new RuntimeEnvVariable('PLAIN', 'p0'))->setId($id), null, $this->rowVersions(), 0)->edit($this->request('POST'), $id);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame($current, $this->rowVersion($id));

        $saved = $this->controller($this->form(null, 'p1', false, (string) $current), (new RuntimeEnvVariable('PLAIN', 'p0'))->setId($id), null, $this->rowVersions(), 1)->edit($this->request('POST'), $id);
        self::assertInstanceOf(RedirectResponse::class, $saved);
        self::assertSame($current + 1, $this->rowVersion($id));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * Audit ZJ-L1: the page reads the row version before the values. A write committed between the two reads leaves
     * the page with the older version, so its save is refused instead of overwriting that write.
     */
    public function testThePageReadsTheVersionBeforeTheValues(): void
    {
        $this->insertVariable($this->connection, 'PLAIN', 'p0');
        $id      = $this->idOf('PLAIN');
        $before  = $this->rowVersion($id);
        $options = [];
        $forms   = function (string $type, mixed $data, array $formOptions) use (&$options): FormInterface {
            if ($type === RuntimeEnvVariableType::class) {
                $options = $formOptions;
            }
            $form = $this->createMock(FormInterface::class);
            $form->method('handleRequest')->willReturnSelf();
            $form->method('isSubmitted')->willReturn(false);
            $form->method('createView')->willReturn(new FormView());

            return $form;
        };
        $onFind = function () use ($id): void {
            // Another admin's save commits between the two reads of the page.
            RuntimeEnvRowVersions::advance($this->connection, 'runtime_env_variables', $id);
        };

        $response = $this->controller($forms, (new RuntimeEnvVariable('PLAIN', 'p0'))->setId($id), null, $this->rowVersions(), null, $onFind)->edit($this->request('GET'), $id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, $options['row_version']);
        self::assertSame($before + 1, $this->rowVersion($id));
    }

    private function idOf(string $name): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM runtime_env_variables WHERE name = ?', [$name]);
    }

    private function rowVersion(int $id): int
    {
        return (int) $this->rowVersions()->current($id);
    }

    private function rowVersions(): RuntimeEnvRowVersions
    {
        return new RuntimeEnvRowVersions($this->registryFor($this->connection), 'default', 'runtime_env_variables');
    }

    private function variable(int $id): RuntimeEnvVariable
    {
        return (new RuntimeEnvVariable('API_TOKEN', 'REDACTED-TEST-A'))->setId($id);
    }

    /**
     * @param (callable(RuntimeEnvVariable): void)|null $fill
     * @param string|null $token the submitted row version (1.9), null for a form without the field
     */
    private function form(?callable $fill, ?string $typed = null, bool $clear = false, ?string $token = null, ?FormView $view = null): callable
    {
        return function (string $type, mixed $data) use ($fill, $typed, $clear, $token, $view): FormInterface {
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
            $tokenField = $this->createMock(FormInterface::class);
            $tokenField->method('getData')->willReturn($token);

            $form = $this->createMock(FormInterface::class);
            $form->method('handleRequest')->willReturnSelf();
            $form->method('isSubmitted')->willReturn(true);
            $form->method('isValid')->willReturn(true);
            $form->method('createView')->willReturn($view ?? new FormView());
            $form->method('has')->willReturnCallback(static fn (string $name): bool => $name !== RuntimeEnvVariableType::ROW_VERSION_FIELD || $token !== null);
            $form->method('get')->willReturnCallback(static fn (string $name): FormInterface => match ($name) {
                'value'                                   => $value,
                RuntimeEnvVariableType::ROW_VERSION_FIELD => $tokenField,
                default                                   => $clearField,
            });

            return $form;
        };
    }

    /**
     * @param int|null $saves how many times the plain writer must save (null: not checked)
     * @param (callable(): void)|null $onFind runs when the controller loads the variable
     */
    private function controller(callable $forms, ?RuntimeEnvVariable $variable = null, ?TokenStorage $tokens = null, ?RuntimeEnvRowVersions $rowVersions = null, ?int $saves = null, ?callable $onFind = null): RuntimeEnvManageController
    {
        $repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(static function () use ($variable, $onFind): ?RuntimeEnvVariable {
            if ($onFind !== null) {
                $onFind();
            }

            return $variable;
        });
        if ($saves !== null) {
            $repository->expects(self::exactly($saves))->method('save');
        }
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturnCallback($forms);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/runtime-env');
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('page');
        $requestStack = new RequestStack();
        $requestStack->push($this->request('GET'));
        $this->stack = $requestStack;

        $controller = new RuntimeEnvManageController($repository, new RuntimeEnvWriter($repository, new RuntimeEnvBag($repository, true)), '/admin/runtime-env', 50, new RuntimeEnvPageFactory($router, new RuntimeEnvPages()), null, $this->settings, $rowVersions);
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
