<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Document;

use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\ExplicitYamlKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ExplicitYamlKeysTest extends TestCase
{
    /**
     * @dataProvider documents
     *
     * @param array<array-key, mixed> $expected
     */
    public function testReadsKeysWrittenAfterAQuestionMark(string $yaml, array $expected): void
    {
        $rewritten = ExplicitYamlKeys::rewrite($yaml);

        // One pass leaves nothing to rewrite, so the decoder reads the text as it is.
        self::assertSame($rewritten, ExplicitYamlKeys::rewrite($rewritten));
        self::assertSame($expected, (new DocumentDecoder())->decode($rewritten, false));
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function documents(): iterable
    {
        yield 'a mapping on the value line' => ["s:\n  ? a.long.name\n  : type: object\n    properties: {p: 1}\n", ['s' => ['a.long.name' => ['type' => 'object', 'properties' => ['p' => 1]]]]];
        yield 'a value below' => ["? k\n:\n  v: 1\n", ['k' => ['v' => 1]]];
        yield 'a scalar value' => ["? k\n: v\n", ['k' => 'v']];
        yield 'a sequence value' => ["? k\n: - a\n  - b\n", ['k' => ['a', 'b']]];
        yield 'a block value' => ["? k\n: |\n  ? not a key\n  : not a value\nn: 1\n", ['k' => "? not a key\n: not a value\n", 'n' => 1]];
        yield 'a folded block value' => ["? k\n: >-\n  a\n  b\n", ['k' => 'a b']];
        yield 'quoted keys' => ["? 'it''s: k'\n: 1\n? \"a \\\"b\\\"\"\n: 2\n", ["it's: k" => 1, 'a "b"' => 2]];
        yield 'comments after keys' => ["? k # note\n: 1\n? 'q' # note\n: 2\nn: 3\n", ['k' => 1, 'q' => 2, 'n' => 3]];
        yield 'a key with signs' => ["? a/b c\\d.e-f\n: 1\n", ['a/b c\\d.e-f' => 1]];
        yield 'a keep block before a key' => ["a: |+\n  text\n? k\n: |\n  x\n", ['a' => "text\n", 'k' => "x\n"]];
        yield 'an anchored block' => ["t: &a |\n  ? x\n  : y\n? k\n: 1\nu: *a\n", ['t' => "? x\n: y\n", 'k' => 1, 'u' => "? x\n: y\n"]];
        yield 'an anchored block value' => ["? k\n: &b |\n  ? x\nu: *b\n", ['k' => "? x\n", 'u' => "? x\n"]];
        yield 'an anchor on the value line' => ["? k\n: &a\n  x: 1\nu: *a\n", ['k' => ['x' => 1], 'u' => ['x' => 1]]];
        yield 'a block in a nested sequence' => ["l:\n  - - |\n      ? x\n      : y\n? k\n: 1\n", ['l' => [["? x\n: y\n"]], 'k' => 1]];
        yield 'a value in a wider column' => ["? k\n:   - a\n    - b\n? m\n:   a:\n      b: 1\n    c: 2\n", ['k' => ['a', 'b'], 'm' => ['a' => ['b' => 1], 'c' => 2]]];
        yield 'a comment after a tab' => ["? a\t# c\n: 1\n", ['a' => 1]];
        yield 'keys without values' => ["? k\n? j\nn: 1\n? last", ['k' => null, 'j' => null, 'n' => 1, 'last' => null]];
        yield 'CRLF' => ["t: |\r\n  ? x\r\n  : y\r\nz:\r\n  ? k\r\n  :\r\n    v: 1\r\n", ['t' => "? x\n: y\n", 'z' => ['k' => ['v' => 1]]]];
        yield 'a block after a dash' => ["l:\n  - |\n    ? x\n    : y\n", ['l' => ["? x\n: y\n"]]];
        yield 'a block indicator in a value' => ["d: a ->\n? k\n: 1\n", ['d' => 'a ->', 'k' => 1]];
        yield 'nested keys' => ["? a\n: ? b\n  : 1\n", ['a' => ['b' => 1]]];
    }

    /**
     * @dataProvider unchanged
     */
    public function testLeavesWhatIsNoScalarKey(string $yaml): void
    {
        self::assertSame($yaml, ExplicitYamlKeys::rewrite($yaml));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unchanged(): iterable
    {
        yield 'a sequence key' => ["? [a, b]\n: v"];
        yield 'a mapping key' => ["? {a: b}\n: v"];
        yield 'an anchor' => ["? &x k\n: v"];
        yield 'an alias' => ["? *x\n: v"];
        yield 'a tag' => ["? !t k\n: v"];
        yield 'an empty key' => ["?  \n: v"];
        yield 'a quoted key with more after it' => ["? 'k' x\n: v"];
        yield 'a mapping key in compact form' => ["? a: b\n: v"];
        yield 'a sequence key in compact form' => ["? - a\n: v"];
        yield 'an explicit key as a key' => ["? ? a\n: v"];
        yield 'a key ending with a colon' => ["? a:\n: v"];
        yield 'no explicit key' => ["a: 1\nb:\n  - c"];
    }

    public function testKeepsTheLineNumbers(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('line 7');

        Yaml::parse(ExplicitYamlKeys::rewrite("? a\n:\n  v: 1\n? b\n: |\n  x\n bad: [\n"));
    }
}
