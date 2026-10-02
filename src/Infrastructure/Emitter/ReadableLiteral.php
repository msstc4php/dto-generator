<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;

final class ReadableLiteral
{
    private function __construct()
    {
    }

    /**
     * Strings with control characters are printed double-quoted with escapes, not as raw multi-line literals.
     */
    public static function of(Expr $value): Expr
    {
        if ($value instanceof String_ && preg_match('/[\x00-\x1f]/', $value->value) === 1) {
            $value->setAttribute('kind', String_::KIND_DOUBLE_QUOTED);
        }

        if ($value instanceof Array_) {
            foreach ($value->items as $item) {
                $item->value = self::of($item->value);
                if ($item->key instanceof Expr) {
                    $item->key = self::of($item->key);
                }
            }
        }

        return $value;
    }
}
