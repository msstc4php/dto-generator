<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use LogicException;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\GeneratedCodePrinter;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\PhpVersion;
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

    public function testLeavesStringLiteralsInASignatureUntouched(): void
    {
        $params = [new Param(new Variable('text'), new String_("x\n)\n{"), new Identifier('string'))];
        $method = new ClassMethod('run', ['params' => $params, 'stmts' => []]);

        // A raw multi-line literal breaks the list, but its bytes stay as they are.
        self::assertSame("function run(\n    string \$text = 'x\n)\n{'\n) {\n}", (new GeneratedCodePrinter())->prettyPrint([$method]));
    }

    public function testBreaksALongArgumentListWithATrailingComma(): void
    {
        $args = [];
        foreach (['firstArgument', 'secondArgument', 'thirdArgument', 'fourthArgument', 'fifthArgument', 'sixthArgument'] as $name) {
            $args[] = new Arg(new Variable($name));
        }

        self::assertSame(
            "new Target(\n    \$firstArgument,\n    \$secondArgument,\n    \$thirdArgument,\n    \$fourthArgument,\n    \$fifthArgument,\n    \$sixthArgument,\n);",
            (new GeneratedCodePrinter())->prettyPrint([new Expression(new New_(new Name('Target'), $args))]),
        );
    }

    public function testBreaksAParameterListAroundAMultiLineDefault(): void
    {
        $items = [];
        foreach (range(1, 12) as $index) {
            $items[] = new ArrayItem(new String_('value-' . $index));
        }

        $params = [new Param(new Variable('a'), new Array_($items), new Identifier('array')), new Param(new Variable('b'))];
        $code = (new GeneratedCodePrinter())->prettyPrint([new ClassMethod('run', ['params' => $params, 'stmts' => []])]);

        self::assertStringStartsWith("function run(\n    array \$a = [\n", $code);
        self::assertStringEndsWith("],\n    \$b\n) {\n}", $code);
    }

    /**
     * @dataProvider trailingCommas
     */
    public function testAddsATrailingCommaToParametersFromPhp80(string $php, string $last): void
    {
        $params = [];
        foreach (['first', 'second', 'third', 'fourth', 'fifth', 'sixth'] as $name) {
            $params[] = new Param(new Variable($name . 'Parameter'), null, new Identifier('string'));
        }

        $printer = new GeneratedCodePrinter(['phpVersion' => PhpVersion::fromString($php)]);

        self::assertStringEndsWith($last . "\n) {\n}", $printer->prettyPrint([new ClassMethod('run', ['params' => $params, 'stmts' => []])]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function trailingCommas(): array
    {
        return [
            '7.4' => ['7.4', 'string $sixthParameter'],
            '8.0' => ['8.0', 'string $sixthParameter,'],
        ];
    }

    public function testKeepsAttributesAndByReferenceInASignature(): void
    {
        $method = new ClassMethod('run', [
            'byRef' => true,
            'flags' => Modifiers::PUBLIC,
            'attrGroups' => [new AttributeGroup([new Attribute(new Name('Pure'))])],
            'stmts' => [],
        ]);

        self::assertSame("#[Pure]\npublic function &run()\n{\n}", (new GeneratedCodePrinter(['phpVersion' => PhpVersion::fromString('8.0')]))->prettyPrint([$method]));
    }

    public function testPrintsDeeplyNestedListsInLinearTime(): void
    {
        $items = [];
        foreach (range(1, 12) as $index) {
            $items[] = new ArrayItem(new String_('value-' . $index));
        }

        $value = new Array_($items);
        foreach (range(1, 30) as $level) {
            $value = new Array_([new ArrayItem($value)]);
        }

        $started = microtime(true);
        $code = (new GeneratedCodePrinter())->prettyPrint([new Expression($value)]);

        // Printing each level twice would take hours at this depth; once, it takes milliseconds.
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame(12, substr_count($code, "'value-"));
    }
}
