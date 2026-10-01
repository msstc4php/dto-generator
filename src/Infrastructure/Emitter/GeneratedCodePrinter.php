<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;

/**
 * The standard printer with PSR-12 spacing: a blank line between members and top-level statements, none
 * between consecutive expression statements, and `declare(strict_types=1);` without the inner space.
 */
final class GeneratedCodePrinter extends Standard
{
    protected function pStmt_Declare(Declare_ $node): string
    {
        return 'declare(' . $this->pCommaSeparated($node->declares) . ')'
            . ($node->stmts !== null ? ' {' . $this->pStmts($node->stmts) . $this->nl . '}' : ';');
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
}
