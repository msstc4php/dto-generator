<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use LogicException;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\GeneratedCodePrinter;
use PhpParser\Modifiers;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;

final class GeneratedCodePrinterTest extends TestCase
{
    public function testSeparatesStatementsUnlessBothAreExpressions(): void
    {
        $expression = new Expression(new Variable('a'));

        self::assertSame(
            "\$a;\n\$a;\n\nreturn;\n\n\$a;",
            (new GeneratedCodePrinter())->prettyPrint([$expression, $expression, new Return_(), $expression]),
        );
    }

    public function testRefusesTheBlockFormOfDeclare(): void
    {
        $this->expectException(LogicException::class);

        (new GeneratedCodePrinter())->prettyPrint([new Declare_([new DeclareItem('ticks', new Int_(1))], [])]);
    }

    public function testBreaksLongListsAndClosesAMultiLineSignatureWithTheBrace(): void
    {
        $params = [];
        foreach (['first', 'second', 'third', 'fourth', 'fifth', 'sixth'] as $name) {
            $params[] = new Param(new Variable($name . 'Parameter'), null, new Identifier('string'));
        }

        $method = new ClassMethod('run', ['flags' => Modifiers::PUBLIC, 'params' => $params, 'returnType' => new Identifier('void'), 'stmts' => []]);

        self::assertSame(
            "public function run(\n    string \$firstParameter,\n    string \$secondParameter,\n    string \$thirdParameter,\n    string \$fourthParameter,\n    string \$fifthParameter,\n    string \$sixthParameter\n): void {\n}",
            (new GeneratedCodePrinter())->prettyPrint([$method]),
        );
    }

    public function testKeepsShortListsAndTheBraceOfASingleLineSignatureOnItsOwnLine(): void
    {
        $method = new ClassMethod('run', ['flags' => Modifiers::PUBLIC, 'params' => [new Param(new Variable('a'))], 'stmts' => []]);

        self::assertSame("public function run(\$a)\n{\n}", (new GeneratedCodePrinter())->prettyPrint([$method]));
    }

    public function testKeepsAListOfExactlyTheLimitOnOneLine(): void
    {
        // "string $a12345678901234567890123, " is 34 characters; two of them plus a 12-character third make 80.
        $params = [
            new Param(new Variable('a12345678901234567890123'), null, new Identifier('string')),
            new Param(new Variable('b12345678901234567890123'), null, new Identifier('string')),
            new Param(new Variable('c1234'), null, new Identifier('array')),
        ];
        $method = new ClassMethod('run', ['params' => $params, 'stmts' => []]);

        self::assertStringStartsWith('function run(string $a12345678901234567890123, string $b12345678901234567890123, array $c1234)' . "\n", (new GeneratedCodePrinter())->prettyPrint([$method]));
    }
}
