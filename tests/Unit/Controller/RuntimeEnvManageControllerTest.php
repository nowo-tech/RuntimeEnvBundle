<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Controller;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Repository\PaginatedRuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\Pagination;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_key_exists;
use function array_keys;
use function dirname;
use function sprintf;

final class RuntimeEnvManageControllerTest extends TestCase
{
    private const INDEX = '@NowoRuntimeEnvBundle/manage/index.html.twig';

    private const FORM = '@NowoRuntimeEnvBundle/manage/form.html.twig';

    /** @var MockObject&RuntimeEnvVariableRepositoryInterface */
    private RuntimeEnvVariableRepositoryInterface $repository;

    private RuntimeEnvWriter $writer;

    /** @var FormFactoryInterface&MockObject */
    private FormFactoryInterface $formFactory;

    /** @var Environment&MockObject */
    private Environment $twig;

    /** @var MockObject&UrlGeneratorInterface */
    private UrlGeneratorInterface $router;

    /** @var CsrfTokenManagerInterface&MockObject */
    private CsrfTokenManagerInterface $csrfTokenManager;

    private RequestStack $requestStack;

    /** @var list<array{view: string, parameters: array<string, mixed>}> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->repository       = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $this->writer           = new RuntimeEnvWriter($this->repository, new RuntimeEnvBag($this->repository, true));
        $this->formFactory      = $this->createMock(FormFactoryInterface::class);
        $this->twig             = $this->createMock(Environment::class);
        $this->router           = $this->createMock(UrlGeneratorInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->requestStack     = new RequestStack();

        $this->twig->method('render')->willReturnCallback(function (string $view, array $parameters): string {
            $this->rendered[] = [
                'view'       => $view,
                'parameters' => $parameters,
            ];

            return $view;
        });
    }

    public function testIndexRendersOnePageOfVariablesFromThePaginatedRepository(): void
    {
        $repository       = $this->createMock(PaginatedRuntimeEnvVariableRepositoryInterface::class);
        $this->repository = $repository;
        $variable         = (new RuntimeEnvVariable('APP_KEY', 'secret'))->setId(3);
        $deleteForm       = $this->createMock(FormInterface::class);
        $deleteForm->method('createView')->willReturn(new FormView());

        $repository->expects(self::once())->method('countAll')->willReturn(5);
        $repository->expects(self::once())
            ->method('findPageOrderedByName')
            ->with(2, 4)
            ->willReturn([$variable]);
        $repository->expects(self::never())->method('findAllOrderedByName');
        $this->formFactory->expects(self::once())
            ->method('create')
            ->with(RuntimeEnvDeleteType::class, null, ['csrf_token_id' => 'runtime_env_delete_3'])
            ->willReturn($deleteForm);

        $response = $this->createController(listPageSize: 2)->index($this->createRequest('/admin/runtime-env?page=9'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::INDEX, $response->getContent());
        $parameters = $this->rendered[0]['parameters'];
        self::assertSame([$variable], $parameters['variables']);
        self::assertArrayHasKey(3, $parameters['delete_forms']);
        self::assertSame('/admin/runtime-env', $parameters['path_prefix']);
        self::assertSame('NowoRuntimeEnvBundle', $parameters['translation_domain']);
        self::assertNull($parameters['nowo_runtime_env_legacy_layout']);

        $page = $parameters['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertSame([$variable], $page->rows);
        self::assertInstanceOf(Pagination::class, $page->pagination);
        self::assertSame(3, $page->pagination->page, 'An out-of-range page is clamped to the last page.');
        self::assertSame(2, $page->pagination->perPage);
        self::assertSame(5, $page->pagination->total);
        self::assertSame('nowo_runtime_env_index', $page->pagination->route);
        self::assertSame('NowoRuntimeEnvBundle', $page->bundle);
    }

    public function testIndexSlicesTheFullListOfARepositoryWithoutPagination(): void
    {
        $variables = [];
        foreach (['A_KEY', 'B_KEY', 'C_KEY'] as $i => $name) {
            $variables[] = (new RuntimeEnvVariable($name, 'x'))->setId($i + 1);
        }
        $variables[] = new RuntimeEnvVariable('DRAFT_KEY', 'x');
        $deleteForm  = $this->createMock(FormInterface::class);
        $deleteForm->method('createView')->willReturn(new FormView());

        $this->repository->expects(self::once())->method('findAllOrderedByName')->willReturn($variables);
        $this->formFactory->expects(self::once())->method('create')->willReturn($deleteForm);

        $this->createController(listPageSize: 2)->index($this->createRequest('/admin/runtime-env?page=2'));

        $parameters = $this->rendered[0]['parameters'];
        self::assertSame([$variables[2], $variables[3]], $parameters['variables']);
        self::assertSame([3], array_keys($parameters['delete_forms']), 'Unsaved variables get no delete form.');
        self::assertSame(4, $parameters['page']->pagination->total);
    }

    public function testIndexBuildsThePageFactoryFromTheRouterWhenNoneIsInjected(): void
    {
        $this->repository->method('findAllOrderedByName')->willReturn([]);
        $this->router->expects(self::atLeastOnce())->method('generate')->willReturn('/admin/runtime-env/new');

        $controller = new RuntimeEnvManageController($this->repository, $this->writer, '/x');
        $reference  = $this->createController();
        $controller->setContainer((new ReflectionProperty($reference, 'container'))->getValue($reference));

        $controller->index($this->createRequest('/x'));

        self::assertSame('@NowoRuntimeEnvBundle/manage/index.html.twig', $this->rendered[0]['view']);
        self::assertInstanceOf(ListPage::class, $this->rendered[0]['parameters']['page']);
    }

    public function testIndexUsesThePerPageTemplateOverride(): void
    {
        $this->repository->method('findAllOrderedByName')->willReturn([]);

        $this->createController(pages: new RuntimeEnvPages(null, ['index' => 'admin/env/index.html.twig']))->index($this->createRequest('/admin/runtime-env'));

        self::assertSame('admin/env/index.html.twig', $this->rendered[0]['view']);
    }

    public function testNewCreatesVariableAndRedirectsWhenFormIsValid(): void
    {
        $form = $this->createFormMock(true, true);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->with(RuntimeEnvVariableType::class, self::isInstanceOf(RuntimeEnvVariable::class), ['is_edit' => false])
            ->willReturn($form);

        $this->repository->expects(self::once())
            ->method('findOneByName')
            ->with('NEW_KEY')
            ->willReturn(null);
        $this->repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (RuntimeEnvVariable $variable): bool {
                return $variable->getName() === 'NEW_KEY'
                    && $variable->getValue() === ''
                    && $variable->getDescription() === null
                    && $variable->isEnabled();
            }));
        $this->router->expects(self::once())
            ->method('generate')
            ->with('nowo_runtime_env_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/runtime-env');

        $request  = $this->createRequest('/admin/runtime-env/new', 'POST');
        $response = $this->createController()->new($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/runtime-env', $response->getTargetUrl());
        self::assertSame(['Variable created.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testNewAddsErrorFlashWhenWriterRejectsValue(): void
    {
        $form = $this->createFormMock(true, true);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($form);
        $this->repository->expects(self::once())
            ->method('findOneByName')
            ->with('NEW_KEY')
            ->willReturn(new RuntimeEnvVariable('NEW_KEY', 'existing'));

        $request  = $this->createRequest('/admin/runtime-env/new', 'POST');
        $response = $this->createController()->new($request);

        self::assertSame(self::FORM, $response->getContent());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(['Runtime env variable "NEW_KEY" already exists.'], $request->getSession()->getFlashBag()->peek('error'));
        self::assertFalse($this->rendered[0]['parameters']['is_edit']);
        self::assertNull($this->rendered[0]['parameters']['page']->deleteAction);
    }

    public function testNewRendersFormWhenRequestHasNotBeenSubmitted(): void
    {
        $form = $this->createFormMock(false, false);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($form);
        $this->repository->expects(self::never())->method('findOneByName');
        $this->repository->expects(self::never())->method('save');

        $request  = $this->createRequest('/admin/runtime-env/new');
        $response = $this->createController()->new($request);

        self::assertSame(self::FORM, $response->getContent());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('/admin/runtime-env', $this->rendered[0]['parameters']['path_prefix']);
        self::assertSame('NowoRuntimeEnvBundle', $this->rendered[0]['parameters']['translation_domain']);
        self::assertInstanceOf(FormPage::class, $this->rendered[0]['parameters']['page']);
    }

    public function testEditThrowsNotFoundWhenVariableDoesNotExist(): void
    {
        $this->repository->expects(self::once())
            ->method('find')
            ->with(42)
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Runtime env variable not found.');

        $this->createController()->edit($this->createRequest('/admin/runtime-env/42/edit'), 42);
    }

    public function testEditUpdatesVariableAndRedirectsWhenFormIsValid(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret', 'Old', true);
        $form     = $this->createFormMock(true, true);

        $this->repository->expects(self::once())
            ->method('find')
            ->with(5)
            ->willReturn($variable);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->with(RuntimeEnvVariableType::class, $variable, ['is_edit' => true])
            ->willReturn($form);
        $this->repository->expects(self::once())
            ->method('save')
            ->with($variable);
        $this->router->expects(self::once())
            ->method('generate')
            ->with('nowo_runtime_env_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/runtime-env');

        $request  = $this->createRequest('/admin/runtime-env/5/edit', 'POST');
        $response = $this->createController()->edit($request, 5);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/runtime-env', $response->getTargetUrl());
        self::assertSame(['Variable updated.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testEditRendersFormWhenFormIsInvalid(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $variable->setId(5);
        $form       = $this->createFormMock(true, false);
        $deleteForm = $this->createMock(FormInterface::class);
        $deleteForm->method('createView')->willReturn(new FormView());

        $this->repository->expects(self::once())
            ->method('find')
            ->with(5)
            ->willReturn($variable);
        $this->formFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($form, $deleteForm);
        $this->repository->expects(self::never())->method('save');

        $response = $this->createController()->edit($this->createRequest('/admin/runtime-env/5/edit', 'POST'), 5);

        self::assertSame(self::FORM, $response->getContent());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertTrue($this->rendered[0]['parameters']['is_edit']);
        self::assertSame($variable, $this->rendered[0]['parameters']['variable']);
        $page = $this->rendered[0]['parameters']['page'];
        self::assertInstanceOf(FormPage::class, $page);
        self::assertNotNull($page->deleteAction);
        self::assertNotNull($page->deleteAction->confirm, 'Delete goes through the UiKit confirm dialog.');
    }

    public function testDeleteThrowsNotFoundWhenVariableDoesNotExist(): void
    {
        $this->repository->expects(self::once())
            ->method('find')
            ->with(7)
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->createController()->delete($this->createRequest('/admin/runtime-env/7/delete', 'POST'), 7);
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $form     = $this->createFormMock(true, false);

        $this->repository->expects(self::once())
            ->method('find')
            ->with(9)
            ->willReturn($variable);
        $this->repository->expects(self::never())->method('remove');
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($form);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $this->createController()->delete($this->createRequest('/admin/runtime-env/9/delete', 'POST'), 9);
    }

    public function testDeleteRemovesVariableAndRedirectsWhenCsrfTokenIsValid(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $form     = $this->createFormMock(true, true);

        $this->repository->expects(self::once())
            ->method('find')
            ->with(9)
            ->willReturn($variable);
        $this->repository->expects(self::once())
            ->method('remove')
            ->with($variable);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($form);
        $this->router->expects(self::once())
            ->method('generate')
            ->with('nowo_runtime_env_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/runtime-env');

        $request  = $this->createRequest('/admin/runtime-env/9/delete', 'POST');
        $response = $this->createController(translator: false)->delete($request, 9);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/runtime-env', $response->getTargetUrl());
        self::assertSame(['runtime_env.flash.deleted'], $request->getSession()->getFlashBag()->peek('success'), 'Without a translator the key is flashed as is.');
    }

    private function translator(): TranslatorInterface
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3) . '/src/Resources/translations/NowoRuntimeEnvBundle.en.yaml', 'en', 'NowoRuntimeEnvBundle');

        return $translator;
    }

    private function createFormMock(bool $submitted, bool $valid): FormInterface
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())
            ->method('handleRequest')
            ->with(self::isInstanceOf(Request::class))
            ->willReturnSelf();
        $form->method('isSubmitted')->willReturn($submitted);
        $form->method('isValid')->willReturn($valid);
        $form->method('createView')->willReturn(new FormView());

        return $form;
    }

    private function createRequest(string $path, string $method = 'GET', array $parameters = []): Request
    {
        $this->requestStack = new RequestStack();
        $this->rendered     = [];

        $request = Request::create($path, $method, $parameters);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    private function createController(int $listPageSize = 50, ?RuntimeEnvPages $pages = null, bool $translator = true): RuntimeEnvManageController
    {
        $controller = new RuntimeEnvManageController(
            $this->repository,
            $this->writer,
            '/admin/runtime-env',
            $listPageSize,
            new RuntimeEnvPageFactory($this->router, $pages ?? new RuntimeEnvPages()),
            $translator ? $this->translator() : null,
        );
        $controller->setContainer(new class(['form.factory' => $this->formFactory, 'twig' => $this->twig, 'router' => $this->router, 'request_stack' => $this->requestStack, 'security.csrf.token_manager' => $this->csrfTokenManager]) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(
                private readonly array $services,
            ) {
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new RuntimeException(sprintf('Missing controller service "%s".', $id));
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        });

        return $controller;
    }
}
