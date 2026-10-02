<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Extension;

use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Infrastructure\Extension\ClassExtensionLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MarkingExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MoneyFormatExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\NeedsArgumentsExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\ThrowingExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClassExtensionLoaderTest extends TestCase
{
    public function testCreatesTheExtensionClass(): void
    {
        self::assertInstanceOf(MarkingExtension::class, (new ClassExtensionLoader())->load(ClassName::fromFqcn(MarkingExtension::class)));
        self::assertInstanceOf(MoneyFormatExtension::class, (new ClassExtensionLoader())->load(ClassName::fromFqcn(MoneyFormatExtension::class)));
    }

    /**
     * @dataProvider failures
     */
    public function testRefusesWhatIsNoUsableExtension(string $class, string $message): void
    {
        $this->expectException(ExtensionFailed::class);
        $this->expectExceptionMessage($message);

        (new ClassExtensionLoader())->load(ClassName::fromFqcn($class));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'missing class' => ['App\Missing\Extension', 'Class App\Missing\Extension does not exist.'],
            'not an extension' => [self::class, 'does not implement MSSTC4PHP\DtoGenerator\Contract\Extension.'],
            'interface' => [Extension::class, 'cannot be instantiated.'],
            'constructor that throws' => [ThrowingExtension::class, 'Class MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\ThrowingExtension could not be created: no env'],
            'constructor arguments' => [NeedsArgumentsExtension::class, 'needs constructor arguments; an extension is created without any.'],
        ];
    }

    public function testReportsAnAutoloaderThatFails(): void
    {
        $autoload = static function (string $class): void {
            if ($class === 'App\Exploding\Extension') {
                throw new RuntimeException('broken autoload');
            }
        };
        spl_autoload_register($autoload);

        try {
            (new ClassExtensionLoader())->load(ClassName::fromFqcn('App\Exploding\Extension'));
            self::fail('The loader accepted a class whose autoloading fails.');
        } catch (ExtensionFailed $exception) {
            self::assertSame('Class App\Exploding\Extension could not be loaded: broken autoload', $exception->getMessage());
        } finally {
            spl_autoload_unregister($autoload);
        }
    }
}
