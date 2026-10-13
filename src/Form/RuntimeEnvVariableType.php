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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function is_string;

/**
 * Create/edit form for a runtime env variable.
 *
 * Values may be empty strings. In edit mode the stored (decrypted) value is never rendered back:
 * the value field starts empty and masked, a blank submission keeps the current value and the
 * `clear_value` checkbox explicitly sets it to an empty string.
 *
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
            ]);

        if ($isEdit) {
            $builder
                ->add('value', TextareaType::class, [
                    'label'    => 'runtime_env.form.value',
                    'mapped'   => false,
                    'required' => false,
                    'help'     => 'runtime_env.form.value_keep_help',
                    'attr'     => [
                        'rows'         => 3,
                        'autocomplete' => 'off',
                        'spellcheck'   => 'false',
                        'class'        => 'nowo-runtime-env-secret',
                        'placeholder'  => 'runtime_env.form.value_keep_placeholder',
                    ],
                ])
                ->add('clear_value', CheckboxType::class, [
                    'label'    => 'runtime_env.form.clear_value',
                    'mapped'   => false,
                    'required' => false,
                ]);

            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $form     = $event->getForm();
                $variable = $form->getData();
                if (!$variable instanceof RuntimeEnvVariable) {
                    return;
                }

                if ($form->get('clear_value')->getData() === true) {
                    $variable->setValue('');

                    return;
                }

                $value = $form->get('value')->getData();
                if (is_string($value) && $value !== '') {
                    $variable->setValue($value);
                }
            });
        } else {
            $builder->add('value', TextareaType::class, [
                'label'      => 'runtime_env.form.value',
                'required'   => false,
                'empty_data' => '',
                'attr'       => ['rows' => 3, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
        }

        $builder
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
