<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerificationFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\AutoloadClassVerifierLocator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        file_put_contents($this->root . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $loader = ClassLoader::getRegisteredLoaders()[$this->root . '/vendor'] ?? null;
        if ($loader instanceof ClassLoader) {
            $loader->unregister();
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    public function testChecksClassesAndConstantsThroughTheConsumersAutoloader(): void
    {
        $verifier = $this->verifier(sprintf(
            "namespace %s;\ninterface Contract { public const KIND = 1; }\ntrait Helper {}\nfinal class Known { public const LEVEL = 1; }\nconst GLOBAL_LEVEL = 2;\n",
            $this->namespace,
        ));

        self::assertTrue($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Known')));
        self::assertFalse($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Contract')));
        self::assertFalse($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Unknown')));
        self::assertTrue($verifier->hasType(ClassName::fromFqcn($this->namespace . '\Known')));
        self::assertTrue($verifier->hasType(ClassName::fromFqcn($this->namespace . '\Contract')));
        self::assertTrue($verifier->hasType(ClassName::fromFqcn($this->namespace . '\Helper')));
        self::assertFalse($verifier->hasType(ClassName::fromFqcn($this->namespace . '\Unknown')));
        self::assertTrue($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Known'), 'LEVEL'));
        self::assertTrue($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Contract'), 'KIND'));
        self::assertFalse($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Known'), 'OTHER'));
        self::assertFalse($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Unknown'), 'LEVEL'));
        self::assertTrue($verifier->hasConstant(null, 'PHP_INT_MAX'));
        self::assertTrue($verifier->hasConstant(null, $this->namespace . '\GLOBAL_LEVEL'));
        self::assertFalse($verifier->hasConstant(null, 'NO_SUCH_CONSTANT_ANYWHERE'));
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testCountsEnumsAsClassesAndTheirCasesAsConstants(): void
    {
        $verifier = $this->verifier(sprintf("namespace %s;\nenum Status: string { case ACTIVE = 'active'; }\n", $this->namespace));

        self::assertTrue($verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Status')));
        self::assertTrue($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Status'), 'ACTIVE'));
        self::assertFalse($verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Status'), 'GONE'));
    }

    public function testLoadsTheAutoloaderOnlyWhenFirstAsked(): void
    {
        $verifier = $this->verifier(sprintf("namespace %s;\nconst LOADED = true;\n", $this->namespace));

        self::assertFalse(defined($this->namespace . '\LOADED'));
        self::assertTrue($verifier->hasConstant(null, $this->namespace . '\LOADED'));
    }

    public function testReportsAnAutoloaderThatThrowsOnEveryQuestion(): void
    {
        $verifier = $this->verifier("throw new \\RuntimeException('boom');\n");

        foreach ([0, 1] as $attempt) {
            try {
                $verifier->hasClass(ClassName::fromFqcn('App\Any'));
                self::fail('The failing autoloader went unnoticed, attempt ' . $attempt . '.');
            } catch (ClassVerificationFailed $exception) {
                self::assertSame('Loading ' . $this->root . '/vendor/autoload.php failed: boom', $exception->getMessage());
            }
        }
    }

    public function testReportsAnAutoloaderThatRaisesAUserError(): void
    {
        // Composer's platform check stops this way; "@" mutes the deprecation of E_USER_ERROR on PHP 8.4.
        $verifier = $this->verifier("@trigger_error('Composer detected issues in your platform', E_USER_ERROR);\n");
        // Outside PHPUnit nothing turns the error into an exception; a handler that lets it pass stands in for that.
        set_error_handler(static fn (): bool => true);

        try {
            $verifier->hasType(ClassName::fromFqcn('App\Any'));
            self::fail('The user error went unnoticed.');
        } catch (ClassVerificationFailed $exception) {
            self::assertSame('Loading ' . $this->root . '/vendor/autoload.php failed: Composer detected issues in your platform', $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testReportsAConstantWhoseValueFailsToResolve(): void
    {
        $verifier = $this->verifier(sprintf("namespace %s;\nfinal class Config { public const PATH = \\Missing\\Root::DIR . '/x'; }\n", $this->namespace));

        $this->expectException(ClassVerificationFailed::class);
        $this->expectExceptionMessage('Checking ' . $this->namespace . '\Config::PATH failed: ');

        $verifier->hasConstant(ClassName::fromFqcn($this->namespace . '\Config'), 'PATH');
    }

    public function testMutesTheDeprecationsOfTheConsumersCode(): void
    {
        $verifier = $this->verifier("trigger_error('Implicitly nullable parameter', E_USER_DEPRECATED);\n");

        self::assertFalse($verifier->hasClass(ClassName::fromFqcn('App\Any')));
    }

    public function testRestoresTheErrorHandlerAfterEachQuestion(): void
    {
        $verifier = $this->verifier('');
        $handler = static fn (): bool => false;
        set_error_handler($handler);

        try {
            $verifier->hasClass(ClassName::fromFqcn('App\Any'));
            $current = set_error_handler(null);
            self::assertSame($handler, $current);
        } finally {
            restore_error_handler();
            restore_error_handler();
        }
    }

    public function testReportsAClassThatFailsToLoad(): void
    {
        file_put_contents($this->root . '/vendor/Broken.php', "<?php\nclass {\n");
        $verifier = $this->verifier(sprintf(
            "spl_autoload_register(static function (string \$class): void { if (\$class === '%s\\\\Broken') { require __DIR__ . '/Broken.php'; } });\n",
            addslashes($this->namespace),
        ));

        $this->expectException(ClassVerificationFailed::class);
        $this->expectExceptionMessage('Checking ' . $this->namespace . '\Broken failed: ');

        $verifier->hasClass(ClassName::fromFqcn($this->namespace . '\Broken'));
    }

    public function testPutsTheConsumersComposerLoaderAfterTheLoadersAlreadyRegistered(): void
    {
        $verifier = $this->verifier("\$loader = new \\Composer\\Autoload\\ClassLoader(__DIR__);\n\$loader->register(true);\n\nreturn \$loader;\n");

        $verifier->hasClass(ClassName::fromFqcn('App\Any'));

        $functions = spl_autoload_functions();
        self::assertIsArray($functions);
        $last = end($functions);
        self::assertIsArray($last);
        self::assertSame(ClassLoader::getRegisteredLoaders()[$this->root . '/vendor'], $last[0]);
    }

    public function testLeavesTheGeneratorsOwnLoaderInPlace(): void
    {
        $own = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $before = spl_autoload_functions();

        $verifier = (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate(dirname($own, 2));
        self::assertInstanceOf(ClassVerifier::class, $verifier);
        $verifier->hasClass(ClassName::fromFqcn(self::class));

        self::assertSame($before, spl_autoload_functions());
    }

    public function testFindsNoVerifierWithoutAnAutoloader(): void
    {
        self::assertNull((new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested/missing-free-zone'));
    }

    public function testStopsAtTheFirstComposerProjectUpwards(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->root . '/config/composer.json', '{}');

        self::assertNull((new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested'));
    }

    public function testHonoursTheVendorDirOfTheComposerProject(): void
    {
        mkdir($this->root . '/lib');
        file_put_contents($this->root . '/lib/autoload.php', sprintf("<?php\nnamespace %s;\nconst FROM_LIB = true;\n", $this->namespace));
        file_put_contents($this->root . '/composer.json', '{"config": {"vendor-dir": "lib"}}');

        $verifier = (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested');

        self::assertInstanceOf(ClassVerifier::class, $verifier);
        self::assertTrue($verifier->hasConstant(null, $this->namespace . '\FROM_LIB'));
    }

    public function testTakesADriveLetterVendorDirAsAbsolute(): void
    {
        mkdir($this->root . '/C:deps', 0777, true);
        file_put_contents($this->root . '/C:deps/autoload.php', "<?php\n");
        file_put_contents($this->root . '/composer.json', '{"config": {"vendor-dir": "C:deps"}}');
        // Taken as relative, the path would also resolve against the working directory, so that is an empty one.
        $cwd = (string) getcwd();
        chdir($this->root . '/config/nested');

        try {
            self::assertNull((new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root));
        } finally {
            chdir($cwd);
        }
    }

    public function testTakesAUncVendorDirAsAbsolute(): void
    {
        mkdir($this->root . '/\\\\server');
        file_put_contents($this->root . '/\\\\server/autoload.php', "<?php\n");
        file_put_contents($this->root . '/composer.json', '{"config": {"vendor-dir": "\\\\\\\\server"}}');
        $cwd = (string) getcwd();
        chdir($this->root . '/config/nested');

        try {
            self::assertNull((new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root));
        } finally {
            chdir($cwd);
        }
    }

    public function testTakesASingleLeadingBackslashAsRelativeLikeComposer(): void
    {
        mkdir($this->root . '/\\deps');
        file_put_contents($this->root . '/\\deps/autoload.php', "<?php\n");
        file_put_contents($this->root . '/composer.json', '{"config": {"vendor-dir": "\\\\deps"}}');

        self::assertInstanceOf(ClassVerifier::class, (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root));
    }

    public function testPrefersTheVendorDirOfTheEnvironment(): void
    {
        mkdir($this->root . '/from-env');
        file_put_contents($this->root . '/from-env/autoload.php', "<?php\n");
        $environment = static fn (string $name): ?string => $name === 'COMPOSER_VENDOR_DIR' ? 'from-env' : null;

        self::assertInstanceOf(ClassVerifier::class, (new AutoloadClassVerifierLocator($environment))->locate($this->root));
    }

    public function testHonoursAnAbsoluteVendorDir(): void
    {
        mkdir($this->root . '/elsewhere');
        file_put_contents($this->root . '/elsewhere/autoload.php', "<?php\n");
        file_put_contents($this->root . '/config/composer.json', sprintf('{"config": {"vendor-dir": "%s/elsewhere"}}', $this->root));

        self::assertInstanceOf(ClassVerifier::class, (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested'));
    }

    public function testFallsBackToVendorWhenComposerJsonIsUnreadable(): void
    {
        chmod($this->root . '/composer.json', 0000);
        if (is_readable($this->root . '/composer.json')) {
            self::markTestSkipped('root can read any file');
        }

        try {
            self::assertTrue($this->verifier('')->hasConstant(null, 'PHP_INT_MAX'));
        } finally {
            chmod($this->root . '/composer.json', 0644);
        }
    }

    public function testFallsBackToVendorWhenTheConfigIsNotAnObject(): void
    {
        file_put_contents($this->root . '/composer.json', '{"config": "lib"}');

        self::assertTrue($this->verifier('')->hasConstant(null, 'PHP_INT_MAX'));
    }

    public function testReadsTheSwitchFromTheEnvironment(): void
    {
        self::assertTrue((new AutoloadClassVerifierLocator(static fn (string $name): ?string => $name === 'DTO_GENERATOR_VERIFY_CLASSES' ? '0' : null))->isDisabledByEnvironment());
        self::assertFalse((new AutoloadClassVerifierLocator(static fn (string $name): ?string => $name === 'DTO_GENERATOR_VERIFY_CLASSES' ? '1' : null))->isDisabledByEnvironment());
        self::assertFalse((new AutoloadClassVerifierLocator(static fn (string $name): ?string => $name === 'COMPOSER_VENDOR_DIR' ? '0' : null))->isDisabledByEnvironment());
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

    private function verifier(string $autoload): ClassVerifier
    {
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n" . $autoload);
        $verifier = (new AutoloadClassVerifierLocator(static fn (): ?string => null))->locate($this->root . '/config/nested');
        self::assertInstanceOf(ClassVerifier::class, $verifier);

        return $verifier;
    }
}
