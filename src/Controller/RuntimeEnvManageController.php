<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Controller;

use InvalidArgumentException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin CRUD for runtime environment variables.
 */
final class RuntimeEnvManageController extends AbstractController
{
    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly RuntimeEnvWriter $writer,
        private readonly array $templates,
        private readonly string $pathPrefix,
    ) {
    }

    public function index(): Response
    {
        $variables = $this->repository->findAllOrderedByName();
        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($variables as $variable) {
            $id = $variable->getId();
            if ($id === null) {
                continue;
            }
            $deleteForms[$id] = $this->createForm(RuntimeEnvDeleteType::class, null, [
                'csrf_token_id' => 'runtime_env_delete_' . $id,
            ])->createView();
        }

        return $this->render($this->templates['index'], [
            'variables'          => $variables,
            'delete_forms'       => $deleteForms,
            'path_prefix'        => $this->pathPrefix,
            'translation_domain' => NowoRuntimeEnvBundle::TRANSLATION_DOMAIN,
        ]);
    }

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
                $this->addFlash('success', 'runtime_env.flash.created');

                return $this->redirectToRoute('nowo_runtime_env_index');
            } catch (InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render($this->templates['form'], [
            'form'               => $form,
            'variable'           => $variable,
            'is_edit'            => false,
            'path_prefix'        => $this->pathPrefix,
            'translation_domain' => NowoRuntimeEnvBundle::TRANSLATION_DOMAIN,
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $variable = $this->repository->find($id);
        if (!$variable instanceof RuntimeEnvVariable) {
            throw new NotFoundHttpException('Runtime env variable not found.');
        }

        $form = $this->createForm(RuntimeEnvVariableType::class, $variable, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->update(
                $variable,
                $variable->getValue(),
                $variable->getDescription(),
                $variable->isEnabled(),
            );
            $this->addFlash('success', 'runtime_env.flash.updated');

            return $this->redirectToRoute('nowo_runtime_env_index');
        }

        return $this->render($this->templates['form'], [
            'form'               => $form,
            'variable'           => $variable,
            'is_edit'            => true,
            'path_prefix'        => $this->pathPrefix,
            'translation_domain' => NowoRuntimeEnvBundle::TRANSLATION_DOMAIN,
        ]);
    }

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
        $this->addFlash('success', 'runtime_env.flash.deleted');

        return $this->redirectToRoute('nowo_runtime_env_index');
    }
}
