<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Controller;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
use Twig\Environment;

use function array_key_exists;
use function sprintf;

final class RuntimeEnvManageControllerTest extends TestCase
{
    private const TEMPLATES = [
        'index'  => '@NowoRuntimeEnvBundle/manage/index.html.twig',
        'form'   => '@NowoRuntimeEnvBundle/manage/form.html.twig',
        'layout' => '@NowoRuntimeEnvBundle/manage/layout.html.twig',
    ];

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

    public function testIndexRendersVariablesList(): void
    {
        $variable   = (new RuntimeEnvVariable('APP_KEY', 'secret'))->setId(3);
        $variables  = [$variable];
        $deleteForm = $this->createMock(FormInterface::class);
        $deleteForm->method('createView')->willReturn(new FormView());

        $this->repository->expects(self::once())
            ->method('findAllOrderedByName')
            ->willReturn($variables);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($deleteForm);

        $response = $this->createController()->index();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::TEMPLATES['index'], $response->getContent());
        self::assertSame($variables, $this->rendered[0]['parameters']['variables']);
        self::assertArrayHasKey(3, $this->rendered[0]['parameters']['delete_forms']);
        self::assertSame('/admin/runtime-env', $this->rendered[0]['parameters']['path_prefix']);
    }

    public function testIndexSkipsDeleteFormsForVariablesWithoutId(): void
    {
        $persisted  = (new RuntimeEnvVariable('APP_KEY', 'secret'))->setId(3);
        $unsaved    = new RuntimeEnvVariable('DRAFT_KEY', 'x');
        $deleteForm = $this->createMock(FormInterface::class);
        $deleteForm->method('createView')->willReturn(new FormView());

        $this->repository->expects(self::once())
            ->method('findAllOrderedByName')
            ->willReturn([$persisted, $unsaved]);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($deleteForm);

        $response = $this->createController()->index();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $deleteForms = $this->rendered[0]['parameters']['delete_forms'];
        self::assertArrayHasKey(3, $deleteForms);
        self::assertCount(1, $deleteForms);
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
        self::assertSame(['runtime_env.flash.created'], $request->getSession()->getFlashBag()->peek('success'));
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

        self::assertSame(self::TEMPLATES['form'], $response->getContent());
        self::assertSame(['Runtime env variable "NEW_KEY" already exists.'], $request->getSession()->getFlashBag()->peek('error'));
        self::assertFalse($this->rendered[0]['parameters']['is_edit']);
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

        self::assertSame(self::TEMPLATES['form'], $response->getContent());
        self::assertSame('/admin/runtime-env', $this->rendered[0]['parameters']['path_prefix']);
        self::assertSame('NowoRuntimeEnvBundle', $this->rendered[0]['parameters']['translation_domain']);
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
        self::assertSame(['runtime_env.flash.updated'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testEditRendersFormWhenFormIsInvalid(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $form     = $this->createFormMock(true, false);

        $this->repository->expects(self::once())
            ->method('find')
            ->with(5)
            ->willReturn($variable);
        $this->formFactory->expects(self::once())
            ->method('create')
            ->willReturn($form);
        $this->repository->expects(self::never())->method('save');

        $response = $this->createController()->edit($this->createRequest('/admin/runtime-env/5/edit', 'POST'), 5);

        self::assertSame(self::TEMPLATES['form'], $response->getContent());
        self::assertTrue($this->rendered[0]['parameters']['is_edit']);
        self::assertSame($variable, $this->rendered[0]['parameters']['variable']);
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
        $response = $this->createController()->delete($request, 9);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/runtime-env', $response->getTargetUrl());
        self::assertSame(['runtime_env.flash.deleted'], $request->getSession()->getFlashBag()->peek('success'));
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

    private function createController(): RuntimeEnvManageController
    {
        $controller = new RuntimeEnvManageController(
            $this->repository,
            $this->writer,
            self::TEMPLATES,
            '/admin/runtime-env',
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
