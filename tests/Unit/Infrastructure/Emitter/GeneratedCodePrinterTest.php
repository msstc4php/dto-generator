<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\GeneratedCodePrinter;
use PhpParser\Node\Expr\Variable;
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
}
