<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class Identifier
{
    /**
     * Tokens of the PHP 7.4 lexer (lowercase): they break a class name on every target and a namespace
     * segment before PHP 8.0.
     */
    private const PHP74_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for',
        'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'interface', 'isset', 'list', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
        'var', 'while', 'xor', 'yield', '__halt_compiler', '__class__', '__dir__', '__file__', '__function__',
        '__line__', '__method__', '__namespace__', '__trait__',
    ];

    /**
     * Not usable as a class name on some supported target: later keywords and reserved type names.
     * `enum` is only a soft keyword (`class Enum` compiles) but is kept here on purpose: such a name
     * reads ambiguously next to generated PHP 8.1 enums and costs nothing to suffix.
     */
    private const RESERVED_CLASS_NAMES = [
        'enum', 'match', 'readonly', '__property__',
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent', 'self',
        'string', 'true', 'void',
    ];

    /** Variables PHP forbids as parameters, so no promoted or assigned property may use them. */
    private const SUPERGLOBALS = ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'];

    private function __construct()
    {
    }

    public static function isValid(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/', $name) === 1;
    }

    public static function isReserved(string $name): bool
    {
        $lower = self::asciiLower($name);

        return in_array($lower, self::PHP74_KEYWORDS, true) || in_array($lower, self::RESERVED_CLASS_NAMES, true);
    }

    /**
     * PHP folds identifiers by ASCII only; strtolower() on PHP 7.4 also depends on the locale.
     */
    public static function asciiLower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    public static function isPhp74Keyword(string $name): bool
    {
        return in_array(self::asciiLower($name), self::PHP74_KEYWORDS, true);
    }

    /**
     * Validates a qualified name (one optional leading backslash) and returns it without that backslash.
     *
     * @param string $kind what the name denotes, for the error message
     */
    public static function normalizeQualifiedName(string $name, string $kind): string
    {
        $normalized = strncmp($name, '\\', 1) === 0 ? (string) substr($name, 1) : $name;
        $segments = explode('\\', $normalized);
        foreach ($segments as $segment) {
            if (!self::isValid($segment)) {
                throw new InvalidModel(sprintf('"%s" is not a valid %s: segment "%s" is not a PHP identifier.', $name, $kind, $segment));
            }
        }

        // `namespace\X` is a namespace-relative name to the lexer on every PHP version.
        if (self::asciiLower($segments[0]) === 'namespace') {
            throw new InvalidModel(sprintf('"%s" is not a valid %s: it cannot start with "namespace".', $name, $kind));
        }

        return $normalized;
    }

    public static function asciiUpperFirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strtr($value[0], 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') . substr($value, 1);
    }

    public static function asciiLowerFirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strtr($value[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') . substr($value, 1);
    }

    public static function isSuperglobal(string $name): bool
    {
        return in_array($name, self::SUPERGLOBALS, true);
    }
}
