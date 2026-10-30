<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\FormTypeInterface;

use function basename;
use function class_uses;
use function glob;

/**
 * REQ-TWIG-005 rule 11: every bundle form type (src/Form/*Type.php) is a FormKit Type on the bundle profile.
 */
final class FormKitTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function formTypes(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Form/*Type.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Nowo\\RuntimeEnvBundle\\Form\\' . basename($file, '.php');

            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('formTypes')]
    public function testFormTypeIsAFormKitType(string $class): void
    {
        $reflection = new ReflectionClass($class);
        self::assertTrue($reflection->implementsInterface(FormTypeInterface::class), $class);

        $isFormKitBase = $reflection->isSubclassOf(FormKitAbstractType::class);
        $usesTrait     = isset(class_uses($class)[FormOptionsTrait::class]);
        self::assertTrue($isFormKitBase || $usesTrait, $class . ' must extend a FormKit base or use FormOptionsTrait');

        $attributes = $reflection->getAttributes(FormKitConfig::class);
        self::assertCount(1, $attributes, $class . ' must carry #[FormKitConfig]');
        self::assertSame(NowoRuntimeEnvBundle::FORM_KIT_PROFILE, $attributes[0]->newInstance()->name);
    }
}
