<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function is_int;
use function is_string;
use function preg_match;

/**
 * Create/edit form for a runtime env variable.
 *
 * Values may be empty strings. In edit mode the stored (decrypted) value is never rendered back:
 * the value field starts empty and masked, a blank submission keeps the current value and the
 * `clear_value` checkbox explicitly sets it to an empty string. With the `row_version` option (1.9) the edit form
 * carries the row version of the page in a hidden, unmapped `rowVersion` field.
 *
 * @extends AbstractType<RuntimeEnvVariable>
 */
#[FormKitConfig(NowoRuntimeEnvBundle::FORM_KIT_PROFILE)]
final class RuntimeEnvVariableType extends AbstractType
{
    use FormOptionsTrait;

    /** 1.9: name of the hidden row version field of the edit form (custom templates that list fields must render it). */
    public const ROW_VERSION_FIELD = 'rowVersion';

    /** 1.9: row version of a form without a valid token: never equal to a stored version (they start at 1). */
    public const MISSING_ROW_VERSION = 0;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = (bool) ($options['is_edit'] ?? false);

        $this->addWithDefaults($builder, 'name', TextType::class, [
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
            $this->addWithDefaults($builder, 'value', TextareaType::class, [
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
            ]);
            $this->addWithDefaults($builder, 'clear_value', CheckboxType::class, [
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
            $this->addWithDefaults($builder, 'value', TextareaType::class, [
                'label'      => 'runtime_env.form.value',
                'required'   => false,
                'empty_data' => '',
                'attr'       => [
                    'rows'         => 3,
                    'autocomplete' => 'off',
                    'spellcheck'   => 'false',
                    'class'        => 'nowo-runtime-env-secret',
                ],
            ]);
        }

        if ($isEdit && is_int($options['row_version'])) {
            // 1.9 (REQ-RUNTIME-003 rule 4): the row version the page was built from, unmapped; the save refuses the edit
            // when someone else saved the variable in between (RuntimeEnvRowVersions).
            $builder->add(self::ROW_VERSION_FIELD, HiddenType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => false,
            ]);
        }

        $this->addWithDefaults($builder, 'description', TextType::class, [
            'label'    => 'runtime_env.form.description',
            'required' => false,
        ]);
        $this->addWithDefaults($builder, 'enabled', CheckboxType::class, [
            'label'    => 'runtime_env.form.enabled',
            'required' => false,
        ]);
    }

    /**
     * A typed secret is never rendered back: after a failed submission (validation error, expired CSRF token,
     * duplicate name) the value field is printed empty, on create and on edit. The row version (1.9) is printed on a
     * fresh edit form only.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (isset($view['value'])) {
            $view['value']->vars['value'] = '';
        }

        // 1.9: the page's row version is printed only on a fresh form. A submitted form keeps the version it was sent
        // with, so a stale page stays stale until it is reloaded; a missing field is never filled in (it never matches).
        if (isset($view[self::ROW_VERSION_FIELD]) && !$form->isSubmitted() && is_int($options['row_version'])) {
            $view[self::ROW_VERSION_FIELD]->vars['value'] = (string) $options['row_version'];
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RuntimeEnvVariable::class,
            'translation_domain' => NowoRuntimeEnvBundle::TRANSLATION_DOMAIN,
            'is_edit'            => false,
            'row_version'        => null,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('row_version', ['null', 'int']);
    }

    /**
     * 1.9: the row version a submitted edit form carries, or {@see self::MISSING_ROW_VERSION} when the field is
     * absent, empty or not a whole number (it never matches a stored version, which starts at 1).
     *
     * @param FormInterface<mixed> $form
     */
    public static function submittedRowVersion(FormInterface $form): int
    {
        $token = $form->has(self::ROW_VERSION_FIELD) ? $form->get(self::ROW_VERSION_FIELD)->getData() : null;

        return is_string($token) && preg_match('/^[1-9][0-9]{0,17}$/', $token) === 1 ? (int) $token : self::MISSING_ROW_VERSION;
    }
}
