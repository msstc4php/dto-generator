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
            'range with comma' => ['>=7.4,<8.0', '7.4'],
            'upper bound first' => ['<8.4 >=8.1', '8.1'],
            'tighter bound first' => ['>=8.1 >=7.4', '8.1'],
            'tighter bound last' => ['>=7.4, >=8.1', '8.1'],
            'hyphen range' => ['7.4 - 8.2', '7.4'],
            'bare version' => ['8.2', '8.2'],
            'exclusive lower bound' => ['>8.0', '8.0'],
            'upper bound only' => ['<8.0', null],
            'upper bound inclusive only' => ['<=8.1', null],
            'exclusion only' => ['!=7.4', null],
            'one alternative bounded below' => ['<7.0 || >=8.1', '8.1'],
            'any' => ['*', null],
            'versionless alternative first' => ['* || ^8.1', '8.1'],
            'equal alternatives' => ['^8.1 || ~8.1.0', '8.1'],
            'empty' => ['', null],
        ];
    }
}
