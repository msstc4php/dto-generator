<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Emitter;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Every generated `parent::__construct(...)` passes the parent's parameters in the parent's own order: a class orders
 * its parameters by its own defaults, which may differ from its subclass's.
 */
final class ParentCallTest extends TestCase
{
    private const DIRS = [__DIR__ . '/../../Fixtures/Emitter/', __DIR__ . '/../../Fixtures/Projects/golden/expected/'];

    /**
     * @dataProvider directories
     */
    public function testPassesTheParentsParametersInItsOrder(string $directory): void
    {
        $parameters = [];
        $calls = [];
        $finder = new NodeFinder();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $files = glob($directory . '*.golden');
        self::assertIsArray($files);
        foreach ($files as $file) {
            $class = $finder->findFirstInstanceOf($parser->parse((string) file_get_contents($file)) ?? [], Class_::class);
            if (!$class instanceof Class_ || !$class->name instanceof Identifier) {
                continue;
            }

            $constructor = $class->getMethod('__construct');
            if (!$constructor instanceof ClassMethod) {
                continue;
            }

            $parameters[$class->name->toString()] = array_map(static fn (Param $param): string => self::name($param->var), $constructor->params);
            $call = $finder->findFirst($constructor->stmts ?? [], static fn ($node): bool => $node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'parent');
            if ($call instanceof StaticCall && $class->extends instanceof Name) {
                $arguments = array_map(static fn ($arg): string => $arg instanceof Arg ? self::name($arg->value) : '...', $call->args);
                $calls[$class->name->toString()] = [$class->extends->getLast(), $arguments];
            }
        }

        self::assertNotSame([], $calls);
        foreach ($calls as $child => [$parent, $arguments]) {
            self::assertArrayHasKey($parent, $parameters, $directory . $child);
            self::assertSame($parameters[$parent], $arguments, $directory . $child);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function directories(): array
    {
        $directories = [];
        foreach (self::DIRS as $root) {
            $found = glob($root . '*', GLOB_ONLYDIR);
            foreach (is_array($found) ? $found : [] as $directory) {
                $directories[basename($root) . '/' . basename($directory)] = [$directory . '/'];
            }
        }

        return $directories;
    }

    private static function name(object $node): string
    {
        return $node instanceof Variable && is_string($node->name) ? $node->name : '?';
    }
}
