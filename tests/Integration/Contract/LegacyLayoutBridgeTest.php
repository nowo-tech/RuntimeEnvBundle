<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Contract;

use Nowo\RuntimeEnvBundle\Tests\Support\PageFixtures;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function preg_grep;
use function restore_error_handler;
use function set_error_handler;
use function substr_count;

use const E_USER_DEPRECATED;

/**
 * Deprecated pre-1.3 manage layouts (no UiKit canonical blocks; the pre-1.3 pages filled `body`) keep working through the
 * bridge: the page renders inside the host `body` block, once, with title, assets and flashes mapped; canonical
 * blocks the pre-1.3 layout renders itself (a copy of the removed bundle layout) are not printed twice. A layout with the
 * canonical blocks is used as is.
 */
#[CoversClass(RuntimeEnvPages::class)]
final class LegacyLayoutBridgeTest extends TestCase
{
    public const LAYOUTS = [
        // A host layout written for 1.2: title, stylesheets, body, javascripts
        'legacy/plain.html.twig' => '<header data-host="plain"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block body %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        // A copy of the removed pre-1.3 bundle layout: renders the flashes itself
        'legacy/bundle-copy.html.twig' => '<header data-host="copy"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}</header>'
            . '<div data-host-flashes>{% block nowo_ui_flashes %}{% endblock %}</div>'
            . '<div data-host-body>{% block body %}{% endblock %}</div>{% block javascripts %}{% endblock %}',
        // A host layout that already renders the UiKit canonical blocks
        'canonical.html.twig' => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
    ];

    #[Test]
    #[IgnoreDeprecations]
    public function aPlainLegacyLayoutRendersThePageInItsBodyBlock(): void
    {
        $twig    = $this->twig();
        $pages   = new RuntimeEnvPages('legacy/plain.html.twig', [], $twig);
        $factory = PageFixtures::factory($pages);

        self::assertSame(RuntimeEnvPages::LEGACY_ADMIN_BRIDGE, $this->withDeprecations($pages->layout(...), '/legacy\/plain\.html\.twig/'));
        $context = PageFixtures::index(false, $factory);
        self::assertSame('legacy/plain.html.twig', $context['nowo_runtime_env_legacy_layout']);
        self::assertSame([], $context['nowo_runtime_env_legacy_blocks']);

        $html = $twig->render('@NowoRuntimeEnvBundle/manage/index.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-host="plain"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="runtime-env-variables"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="runtime-env-variables"'));
        self::assertStringContainsString('<title>Runtime environment variables</title>', $html);
        self::assertStringContainsString('data-host-css', $html);
        self::assertStringContainsString('nowo-ui.css', $html);
        self::assertStringContainsString('data-host-js', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function aCopyOfTheRemovedBundleLayoutDoesNotPrintBlocksTwice(): void
    {
        $twig    = $this->twig();
        $pages   = new RuntimeEnvPages('legacy/bundle-copy.html.twig', [], $twig);
        $factory = PageFixtures::factory($pages);

        $context = $this->withDeprecations(static fn (): array => PageFixtures::form(true, $factory), '/legacy\/bundle-copy\.html\.twig/');
        self::assertSame(['nowo_ui_flashes'], $context['nowo_runtime_env_legacy_blocks']);

        $html = $twig->render('@NowoRuntimeEnvBundle/manage/form.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-testid="runtime-env-variable-form"'));
        self::assertMatchesRegularExpression('/<div data-host-flashes>.*data-nowo-ui-contract-partial="page_header"/s', $html, 'page header before the content');
        self::assertSame(1, substr_count($html, 'data-nowo-ui-contract-partial="page_header"'));
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig();
        $pages = new RuntimeEnvPages('canonical.html.twig', ['index' => 'host/variables.html.twig'], $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame('host/variables.html.twig', $pages->template('index'));
        self::assertSame(RuntimeEnvPages::TEMPLATES['form'], $pages->template('form'));
        self::assertSame(['nowo_runtime_env_legacy_layout' => null, 'nowo_runtime_env_legacy_blocks' => []], $pages->context([]));

        $html = $twig->render('@NowoRuntimeEnvBundle/manage/index.html.twig', PageFixtures::index(false, PageFixtures::factory($pages)));
        self::assertStringContainsString('<div data-host-canonical><title>Runtime environment variables</title>', $html);
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigFallBackToTheConfiguredValue(): void
    {
        $missing = new RuntimeEnvPages('missing/layout.html.twig', [], $this->twig());
        self::assertSame('missing/layout.html.twig', $missing->layout());

        $noTwig = new RuntimeEnvPages('legacy/plain.html.twig');
        self::assertSame('legacy/plain.html.twig', $noTwig->layout());
        self::assertSame(['nowo_runtime_env_legacy_layout' => null, 'nowo_runtime_env_legacy_blocks' => []], $noTwig->context([]));

        $default = new RuntimeEnvPages('');
        self::assertNull($default->layout());
        self::assertSame('unknown_page', $default->template('unknown_page'));
        self::assertSame(['key' => 'k', 'domain' => RuntimeEnvPages::DOMAIN, 'params' => ['%n%' => 1]], (array) RuntimeEnvPages::label('k', ['%n%' => 1]));
    }

    /**
     * Runs $callback, asserting it triggers a user deprecation matching $pattern (PHPUnit 10 has no
     * expectUserDeprecationMessageMatches()).
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withDeprecations(callable $callback, string $pattern): mixed
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        self::assertNotSame([], preg_grep($pattern, $messages), 'Expected a deprecation matching ' . $pattern);

        return $result;
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
            use RuntimeEnvContractEnvironment;

            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function inlineTemplates(): array
            {
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
