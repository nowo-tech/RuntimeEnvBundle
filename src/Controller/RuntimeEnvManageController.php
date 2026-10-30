<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Controller;

use InvalidArgumentException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Repository\PaginatedRuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function ceil;
use function count;
use function max;
use function min;

/**
 * Admin CRUD for runtime environment variables.
 *
 * Routes are declared with #[Route] (REQ-SF-004) and loaded by {@see RuntimeEnvRouteLoader},
 * which prepends `web_ui.path_prefix`. Access is enforced by {@see RuntimeEnvAccessSubscriber}
 * (REQ-UI-002); mutations are CSRF-protected forms (REQ-SEC-005). Stored values are never rendered back.
 */
final class RuntimeEnvManageController extends AbstractController
{
    /** Default rows per page of the manage list (REQ-PERF-001; `web_ui.list_page_size`). */
    public const DEFAULT_LIST_PAGE_SIZE = 50;

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly RuntimeEnvWriter $writer,
        private readonly string $pathPrefix,
        private readonly int $listPageSize = self::DEFAULT_LIST_PAGE_SIZE,
        private readonly ?RuntimeEnvPageFactory $pageFactory = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    #[Route(path: '', name: 'nowo_runtime_env_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // REQ-PERF-001: one COUNT + one bounded SELECT per page (web_ui.list_page_size rows).
        $perPage = max(1, $this->listPageSize);
        if ($this->repository instanceof PaginatedRuntimeEnvVariableRepositoryInterface) {
            $total     = $this->repository->countAll();
            $page      = $this->currentPage($request, $total, $perPage);
            $variables = $this->repository->findPageOrderedByName($perPage, ($page - 1) * $perPage);
        } else {
            // Custom 1.2 repositories without the paginated interface: same page, sliced in memory.
            $all       = $this->repository->findAllOrderedByName();
            $total     = count($all);
            $page      = $this->currentPage($request, $total, $perPage);
            $variables = array_slice($all, ($page - 1) * $perPage, $perPage);
        }

        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($variables as $variable) {
            $id = $variable->getId();
            if ($id === null) {
                continue;
            }
            $deleteForms[$id] = $this->deleteForm($id);
        }

        $pages    = $this->pages();
        $listPage = $pages->index(
            $variables,
            Pagination::fromTotal($total, $page, $perPage, [$perPage], $perPage, 'nowo_runtime_env_index'),
            $deleteForms,
        );

        // No entity reaches the template: `variables` are the same value-free rows as the list page.
        return $this->render($pages->template('index'), $pages->context([
            'page'               => $listPage,
            'variables'          => $listPage->rows,
            'delete_forms'       => $deleteForms,
            'path_prefix'        => $this->pathPrefix,
            'translation_domain' => RuntimeEnvPages::DOMAIN,
        ]));
    }

    #[Route(path: '/new', name: 'nowo_runtime_env_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $variable = new RuntimeEnvVariable('NEW_KEY', '');
        $form     = $this->createForm(RuntimeEnvVariableType::class, $variable, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->writer->create(
                    $variable->getName(),
                    $variable->getValue(),
                    $variable->getDescription(),
                    $variable->isEnabled(),
                );
                $this->addFlash('success', $this->trans('runtime_env.flash.created'));

                return $this->redirectToRoute('nowo_runtime_env_index');
            } catch (InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->renderFormPage($form->createView(), $variable, false, $form->isSubmitted());
    }

    #[Route(path: '/{id}/edit', name: 'nowo_runtime_env_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $variable = $this->repository->find($id);
        if (!$variable instanceof RuntimeEnvVariable) {
            throw new NotFoundHttpException('Runtime env variable not found.');
        }

        // The form edits a detached copy: an invalid submission (e.g. a failed CSRF token) never modifies the managed
        // entity, so a later flush of the same EntityManager in this request cannot persist it.
        $draft = clone $variable;
        $form  = $this->createForm(RuntimeEnvVariableType::class, $draft, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->update(
                $variable,
                $draft->getValue(),
                $draft->getDescription(),
                $draft->isEnabled(),
            );
            $this->addFlash('success', $this->trans('runtime_env.flash.updated'));

            return $this->redirectToRoute('nowo_runtime_env_index');
        }

        return $this->renderFormPage($form->createView(), $variable, true, $form->isSubmitted(), $this->deleteForm($id));
    }

    #[Route(path: '/{id}/delete', name: 'nowo_runtime_env_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        $variable = $this->repository->find($id);
        if (!$variable instanceof RuntimeEnvVariable) {
            throw new NotFoundHttpException('Runtime env variable not found.');
        }

        $form = $this->createForm(RuntimeEnvDeleteType::class, null, [
            'csrf_token_id' => 'runtime_env_delete_' . $id,
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->writer->delete($variable);
        $this->addFlash('success', $this->trans('runtime_env.flash.deleted'));

        return $this->redirectToRoute('nowo_runtime_env_index');
    }

    private function renderFormPage(FormView $form, RuntimeEnvVariable $variable, bool $isEdit, bool $submitted, ?FormView $deleteForm = null): Response
    {
        $pages = $this->pages();

        return $this->render($pages->template('form'), $pages->context([
            'page'               => $pages->form($form, $variable, $isEdit, $deleteForm),
            'form'               => $form,
            'variable'           => RuntimeEnvPageFactory::row($variable),
            'is_edit'            => $isEdit,
            'path_prefix'        => $this->pathPrefix,
            'translation_domain' => RuntimeEnvPages::DOMAIN,
        ]), new Response(status: $submitted ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * CSRF-only delete form of one variable (REQ-TWIG-005: no raw form markup in the views).
     */
    private function deleteForm(int $id): FormView
    {
        return $this->createForm(RuntimeEnvDeleteType::class, null, [
            'csrf_token_id' => 'runtime_env_delete_' . $id,
        ])->createView();
    }

    private function currentPage(Request $request, int $total, int $perPage): int
    {
        $totalPages = max(1, (int) ceil($total / $perPage));

        return min(max(1, $request->query->getInt('page', 1)), $totalPages);
    }

    private function pages(): RuntimeEnvPageFactory
    {
        if ($this->pageFactory instanceof RuntimeEnvPageFactory) {
            return $this->pageFactory;
        }

        /** @var UrlGeneratorInterface $router */
        $router = $this->container->get('router');

        return new RuntimeEnvPageFactory($router);
    }

    /**
     * Flash copy from the bundle catalogue (`NowoRuntimeEnvBundle`); the key itself without a translator.
     */
    private function trans(string $key): string
    {
        return $this->translator instanceof TranslatorInterface
            ? $this->translator->trans($key, [], RuntimeEnvPages::DOMAIN)
            : $key;
    }
}
