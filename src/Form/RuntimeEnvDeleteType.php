<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only delete confirmation form (REQ-TWIG-005 / REQ-SEC-005).
 *
 * @extends AbstractType<null>
 */
final class RuntimeEnvDeleteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => 'runtime_env_delete',
            'translation_domain' => 'NowoRuntimeEnvBundle',
        ]);
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }
}
