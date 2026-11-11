<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host controllers of the worker test: a failing write that closes the bundle manager, and a normal write.
 */
final readonly class PersistenceTestController
{
    public function __construct(
        private RuntimeEnvWriterInterface $writer,
        private ManagerRegistry $registry,
    ) {
    }

    public function set(string $name): Response
    {
        $this->writer->createVariable($name, 'v-' . $name);

        return new Response('ok');
    }

    public function fail(Request $request): Response
    {
        $manager = $this->registry->getManagerForClass(RuntimeEnvVariable::class);
        if (!$manager instanceof EntityManagerInterface) {
            return new Response('no manager', 500);
        }

        $fail = $request->query->getBoolean('fail', true);
        $manager->wrapInTransaction(static function () use ($fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated failed write.');
            }
        });

        return new Response('no failure requested');
    }
}
