<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Ui;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the UiKit view models of the runtime env manage pages (REQ-UI-003): page headers (title, intro, actions, back
 * link), columns, row actions (POST + CSRF FormViews behind a Confirm for deletes), pagination and the empty state. The
 * controller and the page contract test share it, so the test renders exactly the view models the application gets.
 * The view models never carry a variable value. Stateless: safe in worker mode.
 */
final readonly class RuntimeEnvPageFactory
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private RuntimeEnvPages $pages = new RuntimeEnvPages(),
    ) {
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Page variables plus the shared ones (legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Variables list: New variable in the header, Edit / Delete per row.
     *
     * @param list<RuntimeEnvVariable> $variables
     * @param array<int, FormView> $deleteForms variable id => CSRF delete form
     */
    public function index(array $variables, Pagination $pagination, array $deleteForms): ListPage
    {
        $newAction = Action::create($this->url('nowo_runtime_env_new'), RuntimeEnvPages::label('runtime_env.action.new'));

        $actions = [];
        foreach ($variables as $variable) {
            $id           = (int) $variable->getId();
            $actions[$id] = [Action::edit($this->url('nowo_runtime_env_edit', ['id' => $id]), RuntimeEnvPages::label('runtime_env.action.edit'))];
            if (isset($deleteForms[$id])) {
                $actions[$id][] = $this->deleteAction($deleteForms[$id], $variable);
            }
        }

        return new ListPage(
            header: new PageHeader(
                RuntimeEnvPages::label('runtime_env.index.title'),
                RuntimeEnvPages::label('runtime_env.index.subtitle'),
                [$newAction],
            ),
            columns: [
                new Column('name', RuntimeEnvPages::label('runtime_env.form.name'), required: true),
                new Column('value', RuntimeEnvPages::label('runtime_env.form.value')),
                new Column('description', RuntimeEnvPages::label('runtime_env.form.description'), priority: 2),
                new Column('enabled', RuntimeEnvPages::label('runtime_env.form.enabled')),
            ],
            rows: $variables,
            rowActions: static fn (RuntimeEnvVariable $row): array => $actions[(int) $row->getId()] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, RuntimeEnvPages::label('runtime_env.index.empty'), $newAction),
            bundle: RuntimeEnvPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'runtime-env-variables'],
            actionsLabel: RuntimeEnvPages::label('runtime_env.index.actions'),
        );
    }

    /**
     * Variable create / edit (the delete zone on edit).
     */
    public function form(FormView $form, RuntimeEnvVariable $variable, bool $isEdit, ?FormView $deleteForm = null): FormPage
    {
        $indexUrl = $this->url('nowo_runtime_env_index');

        return new FormPage(
            header: new PageHeader(
                RuntimeEnvPages::label($isEdit ? 'runtime_env.form.edit_title' : 'runtime_env.form.new_title'),
                $isEdit ? $variable->getName() : RuntimeEnvPages::label('runtime_env.form.new_intro'),
                back: new BackLink($indexUrl, RuntimeEnvPages::label('runtime_env.action.back'), 'runtime-env-back'),
            ),
            form: $form,
            submitLabel: RuntimeEnvPages::label('runtime_env.action.save'),
            cancelHref: $indexUrl,
            cancelLabel: RuntimeEnvPages::label('runtime_env.action.cancel'),
            deleteAction: $isEdit && $deleteForm instanceof FormView ? $this->deleteAction($deleteForm, $variable) : null,
            bundle: RuntimeEnvPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'runtime-env-variable-form'],
        );
    }

    /**
     * POST delete behind the UiKit confirm dialog; the CSRF FormView carries the token field.
     */
    private function deleteAction(FormView $form, RuntimeEnvVariable $variable): Action
    {
        return Action::delete(
            $this->url('nowo_runtime_env_delete', ['id' => (int) $variable->getId()]),
            confirm: new Confirm(
                RuntimeEnvPages::label('runtime_env.confirm.delete_title'),
                RuntimeEnvPages::label('runtime_env.confirm.delete_message', ['%name%' => $variable->getName()]),
                RuntimeEnvPages::label('runtime_env.confirm.delete_confirm'),
                cancelLabel: RuntimeEnvPages::label('runtime_env.confirm.cancel'),
            ),
            label: RuntimeEnvPages::label('runtime_env.action.delete'),
            form: $form,
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
