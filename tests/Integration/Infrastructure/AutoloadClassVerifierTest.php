<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\AutoloadClassVerifierLocator;
use PHPUnit\Framework\TestCase;

final class AutoloadClassVerifierTest extends TestCase
{
    private string $root;

    private string $namespace;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . '/dto-generator-verify-' . $suffix;
        $this->namespace = 'Consumer\Verify' . $suffix;
        mkdir($this->root . '/vendor', 0777, true);
        mkdir($this->root . '/config/nested', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/vendor/autoload.php')) {
            unlink($this->root . '/vendor/autoload.php');
        }

        rmdir($this->root . '/vendor');
        rmdir($this->root . '/config/nested');
        rmdir($this->root . '/config');
        rmdir($this->root);
    }

    public function testChecksClassesAndConstantsThroughTheConsumersAutoloader(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', sprintf(
            "<?php\nnamespace %s;\ninterface Contract {}\nfinal class Known { public const LEVEL = 1; }\nconst GLOBAL_LEVEL = 2;\n",
            $this->namespace,
        ));

        $verifier = (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested');

        self::assertInstanceOf(ClassVerifier::class, $verifier);
        self::assertTrue($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Known')));
        self::assertTrue($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Contract')));
        self::assertFalse($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Unknown')));
        self::assertTrue($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Known'), 'LEVEL'));
        self::assertFalse($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Known'), 'OTHER'));
        self::assertFalse($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Unknown'), 'LEVEL'));
        self::assertTrue($verifier->hasConstant(null, 'PHP_INT_MAX'));
        self::assertTrue($verifier->hasConstant(null, $this->namespace . '\GLOBAL_LEVEL'));
        self::assertFalse($verifier->hasConstant(null, 'NO_SUCH_CONSTANT_ANYWHERE'));
    }

    public function testFindsNoVerifierWithoutAnAutoloader(): void
    {
        self::assertNull((new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested/missing-free-zone'));
    }

    public function testReadsTheSwitchFromTheEnvironment(): void
    {
        self::assertTrue((new AutoloadClassVerifierLocator(static fn (): string => '0'))->isDisabledByEnvironment());
        self::assertFalse((new AutoloadClassVerifierLocator(static fn (): string => '1'))->isDisabledByEnvironment());
        self::assertFalse((new AutoloadClassVerifierLocator(static fn (): ?string => null))->isDisabledByEnvironment());
    }

    public function testReadsTheRealEnvironmentByDefault(): void
    {
        putenv('DTO_GENERATOR_VERIFY_CLASSES=0');
        try {
            self::assertTrue((new AutoloadClassVerifierLocator())->isDisabledByEnvironment());
        } finally {
            putenv('DTO_GENERATOR_VERIFY_CLASSES');
        }
    }
}
