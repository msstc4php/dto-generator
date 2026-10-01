<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;

/**
 * The standard printer with PSR-12 layout: a blank line between members and top-level statements, none
 * between consecutive expression statements, `declare(strict_types=1);` without the inner space, long
 * parameter and argument lists one per line, and `) {` closing a multi-line signature.
 */
final class GeneratedCodePrinter extends Standard
{
    private const LIST_LIMIT = 80;

    protected function pStmt_Declare(Declare_ $node): string
    {
        if ($node->stmts !== null) {
            throw new LogicException('The block form of declare is never generated.');
        }

        return 'declare(' . $this->pCommaSeparated($node->declares) . ');';
    }

    protected function pStmt_ClassMethod(ClassMethod $node): string
    {
        return (string) preg_replace('/^( *\)(?:: [^\n]+)?)\n *\{/m', '$1 {', parent::pStmt_ClassMethod($node));
    }

    protected function pParams(array $params): string
    {
        return $this->breakIfLong($params, parent::pParams($params), $this->phpVersion->supportsTrailingCommaInParamList());
    }

    protected function pMaybeMultiline(array $nodes, bool $trailingComma = false): string
    {
        return $this->breakIfLong($nodes, parent::pMaybeMultiline($nodes, $trailingComma), $trailingComma);
    }

    protected function pStmts(array $nodes, bool $indent = true): string
    {
        $result = '';
        $previous = null;
        foreach ($nodes as $node) {
            if ($previous !== null && (!$previous instanceof Expression || !$node instanceof Expression)) {
                $result .= "\n";
            }

            $result .= parent::pStmts([$node], $indent);
            $previous = $node;
        }

        return $result;
    }

    /**
     * @param Node\ComplexType|Node\Identifier|Node\Name $type
     */
    public function type(Node $type): string
    {
        $this->resetState();

        return $this->p($type);
    }

    /**
     * @param array<Node> $nodes
     */
    private function breakIfLong(array $nodes, string $printed, bool $trailingComma): string
    {
        if (strpos($printed, "\n") !== false || strlen($printed) <= self::LIST_LIMIT) {
            return $printed;
        }

        return $this->pCommaSeparatedMultiline($nodes, $trailingComma) . $this->nl;
    }
}
