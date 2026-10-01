<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class Identifier
{
    /**
     * Keywords plus reserved type names, lowercase. Conservative on purpose: a generated class name must
     * parse on every supported target, including soft keywords such as `enum` and `readonly`.
     */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally',
        'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'die', '__halt_compiler',
        '__class__', '__dir__', '__file__', '__function__', '__line__', '__method__', '__namespace__',
        '__trait__', '__property__', 'resource', 'numeric',
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent', 'self',
        'string', 'true', 'void',
    ];

    private function __construct()
    {
    }

    public static function isValid(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/', $name) === 1;
    }

    public static function isReserved(string $name): bool
    {
        return in_array(self::asciiLower($name), self::RESERVED, true);
    }

    /**
     * PHP folds identifiers by ASCII only; strtolower() on PHP 7.4 also depends on the locale.
     */
    public static function asciiLower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
