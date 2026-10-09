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
use PhpParser\Node\Stmt\Namespace_;
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
        $parents = [];
        $calls = [];
        $finder = new NodeFinder();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $files = glob($directory . '*.golden');
        self::assertIsArray($files);
        foreach ($files as $file) {
            $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            $class = $finder->findFirstInstanceOf($ast, Class_::class);
            if (!$class instanceof Class_ || !$class->name instanceof Identifier) {
                continue;
            }

            $namespace = $finder->findFirstInstanceOf($ast, Namespace_::class);
            $prefix = $namespace instanceof Namespace_ && $namespace->name instanceof Name ? $namespace->name->toString() . '\\' : '';
            $fqcn = $prefix . $class->name->toString();
            if ($class->extends instanceof Name) {
                $parents[$fqcn] = $class->extends->isFullyQualified() ? $class->extends->toString() : $prefix . $class->extends->toString();
            }

            $constructor = $class->getMethod('__construct');
            if (!$constructor instanceof ClassMethod) {
                continue;
            }

            $parameters[$fqcn] = array_map(static fn (Param $param): string => self::name($param->var), $constructor->params);
            $call = $finder->findFirst($constructor->stmts ?? [], static fn ($node): bool => $node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'parent');
            if ($call instanceof StaticCall) {
                $calls[$fqcn] = array_map(static fn ($arg): string => $arg instanceof Arg ? self::name($arg->value) : '...', $call->args);
            }
        }

        self::assertNotSame([], $calls);
        foreach ($parents as $child => $parent) {
            // A parent outside the fixtures, or one without constructor parameters, needs no call.
            if (($parameters[$parent] ?? []) === []) {
                continue;
            }

            self::assertArrayHasKey($child, $calls, $directory . $child . ' never calls parent::__construct().');
            self::assertSame($parameters[$parent], $calls[$child], $directory . $child);
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
