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
        // The emitter only writes `declare(strict_types=1);`, never the block form.
        return 'declare(' . $this->pCommaSeparated($node->declares) . ');';
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
