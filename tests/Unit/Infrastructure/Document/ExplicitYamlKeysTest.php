<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Document;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\ExplicitYamlKeys;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\RewrittenYaml;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\UndecodableDocument;
use PHPUnit\Framework\TestCase;

final class ExplicitYamlKeysTest extends TestCase
{
    /**
     * @dataProvider documents
     *
     * @param array<array-key, mixed> $expected
     */
    public function testReadsKeysWrittenAfterAQuestionMark(string $yaml, array $expected): void
    {
        self::assertSame($expected, (new DocumentDecoder())->decode($yaml, false));
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
        yield 'a key with signs' => ["? a/b c\\d.e-f#g\n: 1\n", ['a/b c\\d.e-f#g' => 1]];
        yield 'CRLF' => ["t: |\r\n  ? x\r\n  : y\r\nz:\r\n  ? k\r\n  :\r\n    v: 1\r\n", ['t' => "? x\n: y\n", 'z' => ['k' => ['v' => 1]]]];
        yield 'a block after a dash' => ["l:\n  - |\n    ? x\n    : y\n? k\n: 1\n", ['l' => ["? x\n: y\n"], 'k' => 1]];
        yield 'a block indicator in a value' => ["d: a ->\n? k\n: 1\n", ['d' => 'a ->', 'k' => 1]];
        yield 'nested keys' => ["? a\n: ? b\n  : 1\n", ['a' => ['b' => 1]]];
        yield 'a keep block before a key' => ["a: |+\n  text\n? k\n: |\n  x\n", ['a' => "text\n", 'k' => "x\n"]];
        yield 'an anchored block' => ["t: &a |\n  ? x\n  : y\n? k\n: 1\nu: *a\n", ['t' => "? x\n: y\n", 'k' => 1, 'u' => "? x\n: y\n"]];
        yield 'an anchored block value' => ["? k\n: &b |\n  ? x\nu: *b\n", ['k' => "? x\n", 'u' => "? x\n"]];
        yield 'an anchor on the value line' => ["? k\n: &a\n  x: 1\nu: *a\n", ['k' => ['x' => 1], 'u' => ['x' => 1]]];
        yield 'a block in a nested sequence' => ["l:\n  - - |\n      ? x\n      : y\n? k\n: 1\n", ['l' => [["? x\n: y\n"]], 'k' => 1]];
        yield 'a value in a wider column' => ["? k\n:   - a\n    - b\n? m\n:   a:\n      b: 1\n    c: 2\n", ['k' => ['a', 'b'], 'm' => ['a' => ['b' => 1], 'c' => 2]]];
        yield 'a comment after a tab' => ["? a\t# c\n: 1\n", ['a' => 1]];
        yield 'tabs around a block indicator' => ["a: |\t\n  ? x\n  : y\nl:\n  -\t|\n    ? x\n? k\n: 1\n", ['a' => "? x\n: y\n", 'l' => ["? x\n"], 'k' => 1]];
        yield 'a trailing backslash' => ["? k\n: C:\\dir\\\n? j\n:\n  v: 1\n", ['k' => 'C:\\dir\\', 'j' => ['v' => 1]]];
        yield 'a block of a key in a sequence item' => ["l:\n  - a: |\n      t\n    ? k\n    : v\n", ['l' => [['a' => "t\n", 'k' => 'v']]]];
        yield 'a sibling key in a sequence item' => ["l:\n  - a: x\n    ? k\n    : v\n", ['l' => [['a' => 'x', 'k' => 'v']]]];
        yield 'a block after a comment holding a colon' => ["p: # see: |\n  ? k\n  : v\n", ['p' => ['k' => 'v']]];
        yield 'the character of the marks' => ["a: \u{E000}0\u{E000}\n? k\n: 1\n", ['a' => "\u{E000}0\u{E000}", 'k' => 1]];
        yield 'a block of a key holding a hash' => ["a#b: |\n  t:\n  ? x\n  : y\n'a # b': |\n  ? x\n? k\n: 1\n", ['a#b' => "t:\n? x\n: y\n", 'a # b' => "? x\n", 'k' => 1]];
    }

    /**
     * @dataProvider unchanged
     */
    public function testLeavesWhatIsNoScalarKey(string $yaml): void
    {
        self::assertNull(ExplicitYamlKeys::rewrite($yaml));
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
        yield 'a key without a value line' => ["? k\n? j\nn: 1"];
        yield 'a key continued on the next line' => ["s:\n  ? long key\n    continued\n  : 'a: b'"];
        yield 'a comment before the value line' => ["? k\n  # c\n: x: 1"];
        yield 'an empty line before the value line' => ["? k\n\n: a: 1"];
        yield 'a line of a multi-line scalar' => ["a: x\n  ? y\n  : z"];
        yield 'a number' => ["? 0x1F\n: v"];
        yield 'a boolean' => ["? true\n: v"];
        yield 'null' => ["? ~\n: v"];
        yield 'a document that is a block' => ["--- |\n? x\n: y"];
    }

    /**
     * @dataProvider misread
     */
    public function testRejectsADocumentItWouldMisread(string $yaml): void
    {
        $this->expectException(UndecodableDocument::class);
        $this->expectExceptionMessageMatches('/^(?!.*rewritten first)/s');

        (new DocumentDecoder())->decode($yaml, false);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misread(): iterable
    {
        yield 'lines of a quoted scalar' => ["a: 'x\n  y\n  ? z\n  : w'\n? k\n: 1\n"];
        yield 'lines of a quoted scalar after a colon' => ["a: 'x:\n  ? y\n  : z'\n? k\n: 1\n"];
        yield 'lines of a double-quoted scalar' => ["a: \"x:\n  ? 'y'\n  : z\"\n? k\n: 1\n"];
        yield 'lines of a quoted item' => ["l:\n  - 'a\n    b\n  ? c\n  : d'\n? k\n: 1\n"];
        yield 'lines of a quoted scalar before a collection' => ["a: 'x\n  y\n  ? z\n  : - w'\n? k\n: 1\n"];
        yield 'a key in a flow sequence' => ["a: [x, ? k : v]\n? j\n: 1\n"];
        yield 'a key in a flow mapping' => ["a: {? k: v}\n? j\n: 1\n"];
        yield 'a key alone in a flow sequence' => ["a: [? k]\n? j\n: 1\n"];
        yield 'a key in a flow sequence over lines' => ["a: [\n  ? k\n  : v\n  ]\nb:\n  ? j\n  : 1\n"];
        yield 'a forged mark beside a quoted scalar' => ["a: 'x\n  y\n  ? z\n  : w'\n\"\\uE0009\\uE000k\": 1\n? j\n: 2\n"];
        yield 'a forged mark beside a plain scalar' => ["a: x\n  y\n  ? z\n  : w\n\"\\uE0009\\uE000k\": 1\n? j\n: 2\n"];
        yield 'an alias of a mapping with a key after a question mark' => ["a: &x\n  ? k\n  : 1\nb: *x\n"];
        yield 'a comment holding a question mark' => ["# see [? x]\n? k\n: 1\n"];
        yield 'a key after a quoted hash in a flow sequence' => ["x: [ \"a #\", ? b ]\n? k\n: 1\n"];
        yield 'a question mark before a comma' => ["x: [a, ?]\n? k\n: 1\n"];
        yield 'a question mark in a sequence item' => ["x:\n- ?\n? k\n: 1\n"];
        yield 'a key in a sequence item' => ["x:\n  - ? k\n    : v\n? j\n: 1\n"];
        yield 'a value without a key' => ["a:\n  : v\n? k\n: 1\n"];
        yield 'a value without a key in a sequence item' => ["x:\n- : v\n? k\n: 1\n"];
        yield 'a value without a key in a flow mapping' => ["x: {a, : v}\n? k\n: 1\n"];
        yield 'a value without a key after an anchor' => ["x:\n  &a : v\n? k\n: 1\n"];
        yield 'a question mark as a value' => ["a: ? x\n? k\n: 1\n"];
        yield 'a merge of a mapping with a key after a question mark' => ["a: &x\n  ? k\n  : 1\nb:\n  <<: *x\n"];
    }

    public function testRejectsAnInvalidContinuationOfAScalar(): void
    {
        $this->expectException(UndecodableDocument::class);

        (new DocumentDecoder())->decode("a: x\n  y\n  ? z\n  : w\n", false);
    }

    public function testKeepsTheLineNumbers(): void
    {
        $this->expectException(UndecodableDocument::class);
        $this->expectExceptionMessageMatches('/line 7 .*rewritten first/');

        (new DocumentDecoder())->decode("? a\n:\n  v: 1\n? b\n: |\n  x\n bad: [\n", false);
    }

    /**
     * @dataProvider wrongMarks
     *
     * @param array<array-key, mixed> $decoded
     */
    public function testRefusesWhatHoldsAMarkOtherwiseThanAsAKeyOnce(array $decoded): void
    {
        self::assertNull((new RewrittenYaml('', '0a1b', 1))->restore($decoded));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function wrongMarks(): iterable
    {
        $mark = RewrittenYaml::mark('0a1b', 0);

        yield 'a mark missing' => [['k' => 1]];
        yield 'a mark twice' => [[$mark . 'k' => 1, 'n' => [$mark . 'k' => 2]]];
        yield 'a mark in a value' => [['a' => 'x' . $mark . 'k: 1']];
        yield 'a key twice' => [[$mark . 'k' => 1, 'k' => 2]];
        yield 'a mark of another text' => [[RewrittenYaml::mark('ffff', 0) . 'k' => 1]];
        yield 'a mark of another key' => [[RewrittenYaml::mark('0a1b', 1) . 'k' => 1, 'n' => [$mark . 'j' => 2, RewrittenYaml::mark('0a1b', 1) . 'i' => 3]]];
    }

    public function testTakesTheMarksOff(): void
    {
        $rewritten = new RewrittenYaml('text', '0a1b', 2);
        $first = RewrittenYaml::mark('0a1b', 0);

        self::assertSame('text', $rewritten->text());
        self::assertSame(['a' => ['k' => 1, 'n' => ['j' => 'v']]], $rewritten->restore(['a' => [$first . 'k' => 1, 'n' => [RewrittenYaml::mark('0a1b', 1) . 'j' => 'v']]]));
        self::assertSame('near "k", "' . RewrittenYaml::mark('ffff', 0) . '"', $rewritten->withoutMarks('near "' . $first . 'k", "' . RewrittenYaml::mark('ffff', 0) . '"'));
    }

    /**
     * @dataProvider invalidRewrites
     */
    public function testNeedsAHexadecimalNonceAndAKey(string $nonce, int $keys): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Reason: the test hands the constructor what its types rule out, to see it refused.
        new RewrittenYaml('text', $nonce, $keys); // @phpstan-ignore argument.type, argument.type
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidRewrites(): iterable
    {
        yield 'a nonce of other characters' => ['a.b', 1];
        yield 'no key' => ['0a1b', 0];
    }

    public function testMarksEachTextAnew(): void
    {
        $first = ExplicitYamlKeys::rewrite("? k\n: v\n");
        $second = ExplicitYamlKeys::rewrite("? k\n: v\n");

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->text(), $second->text());
    }
}
