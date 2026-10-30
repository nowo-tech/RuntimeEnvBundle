<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvDeleteType;
use Nowo\RuntimeEnvBundle\Form\RuntimeEnvVariableType;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Symfony\Component\Form\PreloadedExtension;

/**
 * FormKit wiring for form tests without a kernel: the bundle form types are FormKit Types
 * (#[FormKitConfig] + FormOptionsTrait) and need the FormOptionsMerger the FormKit compiler pass injects.
 */
final class FormKitTestSupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = RuntimeEnvExtension::formKitProfile();
        unset($profile['alias']);

        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoRuntimeEnvBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];

        return new FormOptionsMerger(
            $profiles,
            NowoRuntimeEnvBundle::FORM_KIT_PROFILE,
            new ConstraintDefinitionFactory(),
        );
    }

    /**
     * Both bundle form types, wired with the merger.
     */
    public static function extension(): PreloadedExtension
    {
        $merger   = self::merger();
        $variable = new RuntimeEnvVariableType();
        $variable->setFormOptionsMerger($merger);
        $delete = new RuntimeEnvDeleteType();
        $delete->setFormOptionsMerger($merger);

        return new PreloadedExtension([$variable, $delete], []);
    }
}
