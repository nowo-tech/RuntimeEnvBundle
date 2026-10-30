<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Support;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

use function http_build_query;

/**
 * Page contexts of the REQ-UI-003 page contract test: the view models come from {@see RuntimeEnvPageFactory} (the
 * service the controller uses), the FormViews from the real form types (CSRF), the other variables mirror the
 * controller. Values are obvious placeholders: the pages must never print them.
 */
final class PageFixtures
{
    /** Placeholder value of every fixture variable; the rendered pages must not contain it. */
    public const SECRET = 'REDACTED-TEST-VALUE';

    /**
     * @return array<string, mixed>
     */
    public static function index(bool $empty = false, ?RuntimeEnvPageFactory $factory = null): array
    {
        $variables = $empty ? [] : [
            self::variable(1, 'FEATURE_CHECKOUT_V2', 'Feature flag', true),
            self::variable(2, 'MY_API_TOKEN', null, false),
        ];
        $deleteForms = [];
        foreach ($variables as $variable) {
            $deleteForms[(int) $variable->getId()] = self::deleteForm((int) $variable->getId());
        }
        $pagination = $empty
            ? Pagination::fromTotal(0, 1, 50, [50], 50, 'nowo_runtime_env_index')
            : Pagination::fromTotal(3, 1, 2, [2], 2, 'nowo_runtime_env_index');
        $factory ??= self::factory();

        return $factory->context([
            'page'               => $factory->index($variables, $pagination, $deleteForms),
            'variables'          => $variables,
            'delete_forms'       => $deleteForms,
            'path_prefix'        => '/_runtime_env',
            'translation_domain' => RuntimeEnvPages::DOMAIN,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function form(bool $edit = false, ?RuntimeEnvPageFactory $factory = null): array
    {
        $variable = $edit ? self::variable(2, 'MY_API_TOKEN', 'Third-party API token', true) : new RuntimeEnvVariable('NEW_KEY', '');
        $form     = self::formFactory()->create(RuntimeEnvVariableType::class, $variable, ['is_edit' => $edit])->createView();
        $factory ??= self::factory();

        return $factory->context([
            'page'               => $factory->form($form, $variable, $edit, $edit ? self::deleteForm(2) : null),
            'form'               => $form,
            'variable'           => $variable,
            'is_edit'            => $edit,
            'path_prefix'        => '/_runtime_env',
            'translation_domain' => RuntimeEnvPages::DOMAIN,
        ]);
    }

    public static function factory(?RuntimeEnvPages $pages = null): RuntimeEnvPageFactory
    {
        return new RuntimeEnvPageFactory(self::urlGenerator(), $pages ?? new RuntimeEnvPages());
    }

    public static function variable(int $id, string $name, ?string $description, bool $enabled): RuntimeEnvVariable
    {
        return (new RuntimeEnvVariable($name, self::SECRET, $description, $enabled))->setId($id);
    }

    /**
     * CSRF delete form of one variable, as built by the controller.
     */
    public static function deleteForm(int $id): FormView
    {
        return self::formFactory()->create(RuntimeEnvDeleteType::class, null, [
            'csrf_token_id' => 'runtime_env_delete_' . $id,
        ])->createView();
    }

    private static function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(new class implements CsrfTokenManagerInterface {
                public function getToken(string $tokenId): CsrfToken
                {
                    return new CsrfToken($tokenId, 'token-' . $tokenId);
                }

                public function refreshToken(string $tokenId): CsrfToken
                {
                    return $this->getToken($tokenId);
                }

                public function removeToken(string $tokenId): ?string
                {
                    return null;
                }

                public function isTokenValid(CsrfToken $token): bool
                {
                    return true;
                }
            }))
            ->getFormFactory();
    }

    private static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
