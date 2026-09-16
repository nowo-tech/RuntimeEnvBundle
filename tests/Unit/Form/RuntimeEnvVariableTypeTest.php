<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Form;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;

final class RuntimeEnvVariableTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension(
                [new RuntimeEnvVariableType()],
                [],
            ),
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testConfigureOptionsAndDefaultFieldSettings(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'secret'));

        self::assertSame(RuntimeEnvVariable::class, $form->getConfig()->getOption('data_class'));
        self::assertSame('NowoRuntimeEnvBundle', $form->getConfig()->getOption('translation_domain'));
        self::assertFalse($form->getConfig()->getOption('is_edit'));

        $name = $form->get('name')->getConfig();
        self::assertSame(TextType::class, $name->getType()->getInnerType()::class);
        self::assertSame('runtime_env.form.name', $name->getOption('label'));
        self::assertFalse($name->getOption('disabled'));

        $constraints = $name->getOption('constraints');
        self::assertCount(2, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(Regex::class, $constraints[1]);
        self::assertSame('Name must match ^[A-Z][A-Z0-9_]*$.', $constraints[1]->message);
    }

    public function testEditModeDisablesNameField(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'secret'), [
            'is_edit' => true,
        ]);

        self::assertTrue($form->get('name')->getConfig()->getOption('disabled'));
    }

    public function testSubmitMapsDataToRuntimeEnvVariable(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');
        $form     = $this->factory->create(RuntimeEnvVariableType::class, $variable);

        $form->submit([
            'name'        => 'API_TOKEN',
            'value'       => 'rotated',
            'description' => 'Rotated token',
            'enabled'     => false,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('API_TOKEN', $variable->getName());
        self::assertSame('rotated', $variable->getValue());
        self::assertSame('Rotated token', $variable->getDescription());
        self::assertFalse($variable->isEnabled());
        self::assertSame(CheckboxType::class, $form->get('enabled')->getConfig()->getType()->getInnerType()::class);
    }

    public function testInvalidIsEditOptionTypeIsRejected(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'secret'), [
            'is_edit' => 'yes',
        ]);
    }
}
