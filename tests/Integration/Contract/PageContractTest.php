<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Contract;

use Nowo\RuntimeEnvBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * REQ-UI-003 page contract: the manage pages extend UiKit scaffolds (list, form); no <html>, CDN, inline handler or
 * confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing translation
 * keys (strict rule); the CSRF forms of the delete actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    use RuntimeEnvContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'variables' => new PageFixture('@NowoRuntimeEnvBundle/manage/index.html.twig', PageFixtures::index());
        yield 'variables (empty)' => new PageFixture('@NowoRuntimeEnvBundle/manage/index.html.twig', PageFixtures::index(true));
        yield 'variable new' => new PageFixture('@NowoRuntimeEnvBundle/manage/form.html.twig', PageFixtures::form());
        yield 'variable edit' => new PageFixture('@NowoRuntimeEnvBundle/manage/form.html.twig', PageFixtures::form(true));
    }

    /**
     * Runtime env values are secrets: no page prints a stored value (list cells are masked, the edit form starts
     * empty), whatever the CSS framework.
     */
    #[DataProvider('contractPages')]
    public function testPageNeverPrintsAStoredValue(PageFixture $fixture): void
    {
        foreach (['none', 'bootstrap5', 'tailwind'] as $framework) {
            self::assertStringNotContainsString(PageFixtures::SECRET, $this->renderPage($fixture, $framework)->html);
        }
    }

    /**
     * 1.9 (REQ-RUNTIME-003 rule 4): the edit page renders the row version as a hidden input of the form (the UiKit
     * child loop), whatever the CSS framework, and never on the create page.
     */
    public function testTheEditPageCarriesTheRowVersionInAHiddenInput(): void
    {
        foreach (['none', 'bootstrap5', 'tailwind'] as $framework) {
            $html = $this->renderPage(new PageFixture('@NowoRuntimeEnvBundle/manage/form.html.twig', PageFixtures::form(true)), $framework)->html;
            self::assertMatchesRegularExpression('/<input type="hidden" id="runtime_env_variable_rowVersion" name="runtime_env_variable\[rowVersion\]" value="' . PageFixtures::ROW_VERSION . '"/', $html, $framework);

            $new = $this->renderPage(new PageFixture('@NowoRuntimeEnvBundle/manage/form.html.twig', PageFixtures::form()), $framework)->html;
            self::assertStringNotContainsString('rowVersion', $new, $framework);
        }
    }

    /**
     * A host `web_ui.templates.index` that extends the plain UiKit list scaffold (default cells, no bundle row
     * override) still never prints a value: the rows are value-free and the value column key resolves to nothing.
     */
    public function testPlainUiKitListScaffoldNeverPrintsAStoredValue(): void
    {
        $html = $this->renderPage(new PageFixture('host/plain_list.html.twig', PageFixtures::index()), 'bootstrap5')->html;

        self::assertStringContainsString('FEATURE_CHECKOUT_V2', $html);
        self::assertStringNotContainsString(PageFixtures::SECRET, $html);
    }

    protected static function inlineTemplates(): array
    {
        return ['host/plain_list.html.twig' => "{% extends '@NowoUiKit/page/list.html.twig' %}"];
    }
}
