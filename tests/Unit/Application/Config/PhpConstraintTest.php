<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Config\PhpConstraint;
use PHPUnit\Framework\TestCase;

final class PhpConstraintTest extends TestCase
{
    /**
     * @dataProvider constraints
     */
    public function testFindsTheLowestAdmittedMinor(string $constraint, ?string $expected): void
    {
        self::assertSame($expected, PhpConstraint::lowestMinor($constraint));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function constraints(): array
    {
        return [
            'greater or equal' => ['>=7.4', '7.4'],
            'caret' => ['^8.1', '8.1'],
            'tilde with patch' => ['~8.2.0', '8.2'],
            'wildcard' => ['8.1.*', '8.1'],
            'or' => ['^7.4 || ^8.0', '7.4'],
            'single pipe, higher first' => ['^8.0|^7.4', '7.4'],
            'major only' => ['>=8', '8.0'],
            'range' => ['>=7.4 <8.3', '7.4'],
            'any' => ['*', null],
            'versionless alternative first' => ['* || ^8.1', '8.1'],
            'equal alternatives' => ['^8.1 || ~8.1.0', '8.1'],
            'empty' => ['', null],
        ];
    }
}
