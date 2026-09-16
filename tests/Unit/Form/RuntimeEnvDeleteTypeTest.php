<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Form;

use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

final class RuntimeEnvDeleteTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension(
                [new RuntimeEnvDeleteType()],
                [],
            ),
        ];
    }

    public function testDefaultsEnableCsrfProtection(): void
    {
        $form = $this->factory->create(RuntimeEnvDeleteType::class);

        self::assertTrue($form->getConfig()->getOption('csrf_protection'));
        self::assertSame('_token', $form->getConfig()->getOption('csrf_field_name'));
        self::assertSame('runtime_env_delete', $form->getConfig()->getOption('csrf_token_id'));
        self::assertSame('NowoRuntimeEnvBundle', $form->getConfig()->getOption('translation_domain'));
        self::assertCount(0, $form->all());
    }

    public function testCustomCsrfTokenIdIsAllowed(): void
    {
        $form = $this->factory->create(RuntimeEnvDeleteType::class, null, [
            'csrf_token_id' => 'runtime_env_delete_42',
        ]);

        self::assertSame('runtime_env_delete_42', $form->getConfig()->getOption('csrf_token_id'));
    }
}
