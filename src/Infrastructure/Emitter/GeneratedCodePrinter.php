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
    /** Bytes of the comma-separated list itself, not of the whole line. */
    private const LIST_LIMIT = 80;

    /**
     * @param Node\ComplexType|Node\Identifier|Node\Name $type
     */
    public function type(Node $type): string
    {
        $this->resetState();

        return $this->p($type);
    }

    protected function pStmt_Declare(Declare_ $node): string
    {
        if ($node->stmts !== null) {
            throw new LogicException('The block form of declare is never generated.');
        }

        return 'declare(' . $this->pCommaSeparated($node->declares) . ');';
    }

    /**
     * The standard layout, except that a multi-line parameter list closes with ") {" (PSR-12 §4.4).
     */
    protected function pStmt_ClassMethod(ClassMethod $node): string
    {
        $params = $this->pParams($node->params);
        // Only a multi-line list ends with a line break; a literal inside a single-line one is always quoted.
        $multiline = substr($params, -strlen($this->nl)) === $this->nl;

        return $this->pAttrGroups($node->attrGroups)
            . $this->pModifiers($node->flags)
            . 'function ' . ($node->byRef ? '&' : '') . $node->name
            . '(' . $params . ')'
            . ($node->returnType instanceof Node ? ': ' . $this->p($node->returnType) : '')
            . ($node->stmts !== null
                ? ($multiline ? ' {' : $this->nl . '{') . $this->pStmts($node->stmts) . $this->nl . '}'
                : ';');
    }

    protected function pParams(array $params): string
    {
        return $this->breakIfLong($params, parent::pParams($params), $this->phpVersion->supportsTrailingCommaInParamList());
    }

    protected function pMaybeMultiline(array $nodes, bool $trailingComma = false): string
    {
        // A trailing comma in calls and arrays is valid from PHP 7.3, so every target gets one once the list breaks.
        return $this->breakIfLong($nodes, parent::pMaybeMultiline($nodes, $trailingComma), true);
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
     * @param array<Node> $nodes
     */
    private function breakIfLong(array $nodes, string $printed, bool $trailingComma): string
    {
        // A list holding a multi-line element (a long array default) breaks too, one element per line.
        if (strpos($printed, "\n") === false && strlen($printed) <= self::LIST_LIMIT) {
            return $printed;
        }

        return $this->pCommaSeparatedMultiline($nodes, $trailingComma) . $this->nl;
    }
}
