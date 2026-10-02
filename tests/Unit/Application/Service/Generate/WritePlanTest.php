<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Generate;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\FileChange;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;
use PHPUnit\Framework\TestCase;

final class WritePlanTest extends TestCase
{
    public function testExposesAGeneratedFile(): void
    {
        $file = new GeneratedFile('/out', 'Sub/User.php', '<?php');

        self::assertSame('/out', $file->outputDir());
        self::assertSame('Sub/User.php', $file->relativePath());
        self::assertSame('/out/Sub/User.php', $file->path());
        self::assertSame('<?php', $file->contents());
    }

    /**
     * @dataProvider unsafePaths
     */
    public function testRejectsPathsOutsideTheOutputDirectory(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a relative path inside the output directory');

        new GeneratedFile('/out', $path, '');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'absolute' => ['/etc/passwd'],
            'parent' => ['../User.php'],
            'nested parent' => ['A/../../User.php'],
        ];
    }

    public function testDescribesChanges(): void
    {
        $create = FileChange::create('/out/A.php', 'a');
        $delete = FileChange::delete('/out/B.php');

        self::assertSame(['create', '/out/A.php', 'a'], [$create->kind(), $create->path(), $create->contents()]);
        self::assertSame(['delete', '/out/B.php', null], [$delete->kind(), $delete->path(), $delete->contents()]);
        self::assertSame(['update', 'b'], [FileChange::update('/out/C.php', 'b')->kind(), FileChange::update('/out/C.php', 'b')->contents()]);
        self::assertSame('unchanged', FileChange::unchanged('/out/D.php')->kind());
        self::assertTrue($create->isChange());
        self::assertFalse(FileChange::unchanged('/out/D.php')->isChange());
    }

    public function testKnowsWhetherAnythingChanges(): void
    {
        $unchanged = FileChange::unchanged('/out/A.php');

        self::assertFalse((new WritePlan([$unchanged], [], []))->hasChanges());
        self::assertTrue((new WritePlan([$unchanged, FileChange::delete('/out/B.php')], [], []))->hasChanges());
        self::assertTrue((new WritePlan([$unchanged], [], ['/out/.dto-generator.manifest.json' => '{}']))->hasChanges());
    }

    public function testExposesItsParts(): void
    {
        $plan = new WritePlan([FileChange::create('/out/A.php', 'a')], ['/out/B.php: conflict'], ['/out/m.json' => '{}']);

        self::assertCount(1, $plan->changes());
        self::assertSame(['/out/B.php: conflict'], $plan->conflicts());
        self::assertSame(['/out/m.json' => '{}'], $plan->manifests());
    }
}
