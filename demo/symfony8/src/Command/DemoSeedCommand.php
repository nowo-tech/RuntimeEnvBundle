<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function sprintf;

/**
 * Seeds demo runtime env variables (idempotent): more rows than one manage page (web_ui.list_page_size: 10) so the
 * pagination shows. Values are obvious placeholders, never real secrets.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed demo runtime env variables (idempotent)')]
final class DemoSeedCommand extends Command
{
    private const VARIABLES = [
        'DEMO_SAMPLE_VALUE'       => ['hello-from-runtime-env', 'Read by the demo home page', true],
        'MY_API_TOKEN'            => ['demo-placeholder-token', 'Third-party API token', true],
        'FEATURE_CHECKOUT_V2'     => ['1', 'Feature flag: new checkout', true],
        'FEATURE_DARK_MODE'       => ['0', 'Feature flag: dark mode', false],
        'MAILER_FROM_ADDRESS'     => ['noreply@example.com', 'Sender of transactional mail', true],
        'PAYMENT_PROVIDER'        => ['sandbox', 'Payment gateway mode', true],
        'PAYMENT_WEBHOOK_SECRET'  => ['demo-placeholder-secret', 'Webhook signature secret', true],
        'SEARCH_RESULTS_PER_PAGE' => ['20', 'Public search page size', true],
        'SUPPORT_PHONE'           => ['+34 900 000 000', 'Shown in the footer', true],
        'MAINTENANCE_BANNER'      => ['', 'Empty = no banner', false],
        'ANALYTICS_SITE_ID'       => ['demo-site-id', 'Analytics property', true],
        'CDN_BASE_URL'            => ['https://cdn.example.com', 'Static assets host', true],
    ];

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly RuntimeEnvWriter $writer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = 0;
        foreach (self::VARIABLES as $name => [$value, $description, $enabled]) {
            if ($this->repository->findOneByName($name) !== null) {
                continue;
            }
            $this->writer->create($name, $value, $description, $enabled);
            ++$created;
        }

        $output->writeln(sprintf('Demo runtime env variables: %d created, %d total.', $created, count(self::VARIABLES)));

        return Command::SUCCESS;
    }
}
