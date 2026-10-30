<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Form;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\Tests\Support\FormKitTestSupport;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
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
            FormKitTestSupport::extension(),
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

    public function testNewVariableAcceptsAnEmptyValue(): void
    {
        $variable = new RuntimeEnvVariable('NEW_KEY', 'placeholder');
        $form     = $this->factory->create(RuntimeEnvVariableType::class, $variable);

        $form->submit(['name' => 'FEATURE_SUFFIX', 'value' => '', 'description' => '', 'enabled' => true]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('', $variable->getValue());
        self::assertFalse($form->get('value')->getConfig()->getRequired());
    }

    public function testEditNeverRendersTheStoredSecret(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'top-secret'), [
            'is_edit' => true,
        ]);

        $value = $form->get('value');
        self::assertFalse($value->getConfig()->getMapped());
        self::assertSame('', $form->createView()['value']->vars['value']);
        self::assertSame('runtime_env.form.value_keep_help', $value->getConfig()->getOption('help'));
        self::assertSame('nowo-runtime-env-secret', $value->getConfig()->getOption('attr')['class']);
    }

    public function testEditWithBlankValueKeepsTheCurrentValue(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'top-secret');
        $form     = $this->factory->create(RuntimeEnvVariableType::class, $variable, ['is_edit' => true]);

        $form->submit(['value' => '', 'description' => 'Updated', 'enabled' => true]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('top-secret', $variable->getValue());
        self::assertSame('Updated', $variable->getDescription());
    }

    public function testEditWithNewValueReplacesIt(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'top-secret');
        $form     = $this->factory->create(RuntimeEnvVariableType::class, $variable, ['is_edit' => true]);

        $form->submit(['value' => 'rotated', 'enabled' => true]);

        self::assertTrue($form->isValid());
        self::assertSame('rotated', $variable->getValue());
    }

    public function testEditClearValueSetsAnEmptyString(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'top-secret');
        $form     = $this->factory->create(RuntimeEnvVariableType::class, $variable, ['is_edit' => true]);

        $form->submit(['value' => 'ignored', 'clear_value' => '1', 'enabled' => true]);

        self::assertTrue($form->isValid());
        self::assertSame('', $variable->getValue());
    }

    public function testEditListenerIgnoresNonEntityData(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, null, ['is_edit' => true, 'data_class' => null]);

        $form->submit(['value' => 'x']);

        self::assertNull($form->getData()['name'] ?? null);
    }

    public function testInvalidIsEditOptionTypeIsRejected(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'secret'), [
            'is_edit' => 'yes',
        ]);
    }

    public function testSubmittedValueIsNeverRenderedBackOnCreate(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('NEW_KEY', ''));
        $form->submit(['name' => 'not valid', 'value' => 'REDACTED-TEST-TYPED', 'description' => '', 'enabled' => '1']);

        self::assertFalse($form->isValid());
        $view = $form->createView();
        self::assertSame('', $view['value']->vars['value']);
        self::assertSame('nowo-runtime-env-secret', $view['value']->vars['attr']['class']);
    }

    public function testSubmittedValueIsNeverRenderedBackOnEdit(): void
    {
        $form = $this->factory->create(RuntimeEnvVariableType::class, new RuntimeEnvVariable('APP_KEY', 'REDACTED-TEST-STORED'), ['is_edit' => true]);
        $form->submit(['value' => 'REDACTED-TEST-TYPED', 'description' => '', 'enabled' => '1']);

        self::assertSame('', $form->createView()['value']->vars['value']);
    }
}
