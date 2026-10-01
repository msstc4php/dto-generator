<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use PHPUnit\Framework\TestCase;

final class ImportAliasTest extends TestCase
{
    public function testNormalizesTheNamespace(): void
    {
        $alias = new ImportAlias('\\Symfony\\Component\\Validator\\Constraints', 'Assert');

        self::assertSame('Symfony\\Component\\Validator\\Constraints', $alias->namespace());
        self::assertSame('Assert', $alias->alias());
    }

    public function testAllowsReservedWordsInTheNamespaceLikeClassNameDoes(): void
    {
        self::assertSame('App\\Validator\\Enum', (new ImportAlias('App\\Validator\\Enum', 'E'))->namespace());
    }

    public function testRejectsAReservedAlias(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Import alias "List" is not a usable PHP identifier');

        new ImportAlias('App\\Rules', 'List');
    }

    /**
     * @dataProvider invalidNamespaces
     */
    public function testRejectsAnInvalidNamespace(string $namespace): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not a valid namespace');

        new ImportAlias($namespace, 'Assert');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNamespaces(): array
    {
        return [
            'empty' => [''],
            'leading digit segment' => ['App\\1Rules'],
            'empty segment' => ['App\\\\Rules'],
            'double leading backslash' => ['\\\\App\\Rules'],
            'namespace keyword first' => ['namespace\\Rules'],
        ];
    }
}
