<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Ui;

use Nowo\UiKitBundle\View\Label;
use Throwable;
use Twig\Environment;

use function is_string;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layout the manage pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), wraps a pre-1.3 layout (no UiKit canonical blocks) in a
 * deprecated bridge, and resolves the per-page template overrides (`web_ui.templates.<page>`). Configuration only: no
 * request state, safe in worker mode.
 */
final readonly class RuntimeEnvPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoRuntimeEnvBundle`). */
    public const BUNDLE = 'NowoRuntimeEnvBundle';

    /** Translation domain of the bundle labels (REQ-I18N-003). */
    public const DOMAIN = 'NowoRuntimeEnvBundle';

    /** BC bridge around a pre-1.3 manage layout that renders `{% block body %}` (removed in 2.0). */
    public const LEGACY_ADMIN_BRIDGE = '@NowoRuntimeEnvBundle/manage/_legacy_layout.html.twig';

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'index' => '@NowoRuntimeEnvBundle/manage/index.html.twig',
        'form'  => '@NowoRuntimeEnvBundle/manage/form.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /** Canonical blocks a pre-1.3 layout may already render (the bridge skips them). */
    private const LEGACY_HOST_BLOCKS = ['nowo_ui_flashes', 'nowo_ui_styles', 'nowo_ui_scripts'];

    /**
     * @param array<string, string> $templates `web_ui.templates` (page id => template)
     */
    public function __construct(
        private ?string $layoutTemplate = null,
        private array $templates = [],
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$page] ?? $page;
    }

    /**
     * Layout the scaffolds extend (`page.layout`): `web_ui.layout_template`, the legacy bridge for a pre-1.3 layout, else
     * null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }

        return $this->legacy($this->layoutTemplate) !== null ? self::LEGACY_ADMIN_BRIDGE : $this->layoutTemplate;
    }

    /**
     * Shared variables of the manage pages (added to the controller variables): the pre-1.3 layout wrapped by the
     * deprecated bridge and the canonical blocks it already renders.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        $legacy = $this->layoutTemplate !== null && $this->layoutTemplate !== '' ? $this->legacy($this->layoutTemplate, false) : null;

        return $vars + [
            'nowo_runtime_env_legacy_layout' => $legacy,
            'nowo_runtime_env_legacy_blocks' => $this->hostBlocks($legacy),
        ];
    }

    /**
     * Canonical blocks a pre-1.3 layout defines itself (the bridge does not render them a second time).
     *
     * @return list<string>
     */
    private function hostBlocks(?string $legacy): array
    {
        if ($legacy === null || !$this->twig instanceof Environment) {
            return [];
        }

        $template = $this->twig->load($legacy);
        $context  = $this->twig->mergeGlobals([]);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block, $context)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The configured layout when it is a pre-1.3 layout (it does not define the UiKit canonical blocks), else null.
     */
    private function legacy(string $layout, bool $deprecate = true): ?string
    {
        if (!$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        if ($deprecate) {
            trigger_deprecation('nowo-tech/runtime-env-bundle', '1.3', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a deprecated bridge. Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig) or set nowo_runtime_env.web_ui.layout_template to null to use nowo_ui_kit.layouts.admin; see docs/UPGRADING.md.', $layout);
        }

        return $layout;
    }
}
