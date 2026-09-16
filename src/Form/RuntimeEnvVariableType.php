<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Form;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<RuntimeEnvVariable>
 */
final class RuntimeEnvVariableType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = (bool) ($options['is_edit'] ?? false);

        $builder
            ->add('name', TextType::class, [
                'label'       => 'runtime_env.form.name',
                'disabled'    => $isEdit,
                'constraints' => [
                    new NotBlank(),
                    new Regex(
                        pattern: RuntimeEnvWriter::NAME_PATTERN,
                        message: 'Name must match ^[A-Z][A-Z0-9_]*$.',
                    ),
                ],
            ])
            ->add('value', TextareaType::class, [
                'label'       => 'runtime_env.form.value',
                'required'    => true,
                'constraints' => [new NotBlank()],
                'attr'        => ['rows' => 3, 'autocomplete' => 'off'],
            ])
            ->add('description', TextType::class, [
                'label'    => 'runtime_env.form.description',
                'required' => false,
            ])
            ->add('enabled', CheckboxType::class, [
                'label'    => 'runtime_env.form.enabled',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RuntimeEnvVariable::class,
            'translation_domain' => 'NowoRuntimeEnvBundle',
            'is_edit'            => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
