<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use PHPUnit\Framework\TestCase;

final class ViewSuffixesTest extends TestCase
{
    public function testNamesTheSuffixOfEachDirection(): void
    {
        $suffixes = new ViewSuffixes('Response', 'Request');

        self::assertSame('Response', $suffixes->of(Direction::from(Direction::READ)));
        self::assertSame('Request', $suffixes->of(Direction::from(Direction::WRITE)));
        self::assertSame(['Read', 'Write'], [(new ViewSuffixes())->read(), (new ViewSuffixes())->write()]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid(): array
    {
        return [
            'read' => ['Read-Model', 'Write'],
            'write' => ['Read', '1Write'],
            'same' => ['Model', 'Model'],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testRefusesSuffixesThatAreNoDistinctIdentifiers(string $read, string $write): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('View suffixes must be distinct PHP identifiers, got "%s" and "%s".', $read, $write));

        new ViewSuffixes($read, $write);
    }
}
