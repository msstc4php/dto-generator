<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Contract;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use PHPUnit\Framework\TestCase;

final class InstalledPackagesTest extends TestCase
{
    public function testAnswersVersionsOfInstalledPackages(): void
    {
        $packages = new InstalledPackages(['symfony/validator' => '7.1.2']);

        self::assertTrue($packages->has('symfony/validator'));
        self::assertSame('7.1.2', $packages->version('symfony/validator'));
        self::assertFalse($packages->has('symfony/serializer'));
        self::assertNull($packages->version('symfony/serializer'));
        self::assertFalse((new InstalledPackages())->has('symfony/validator'));
    }

    public function testRejectsAPackageWithoutVersion(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Package "a/b" has no version.');

        new InstalledPackages(['a/b' => '']);
    }
}
