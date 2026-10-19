<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

/**
 * A YAML text whose keys written after "?" are written before their ":" instead, each with a mark of this text: a
 * character of the Unicode private use area around a number no document can guess and the key's own number.
 */
final class RewrittenYaml
{
    private const MARK = "\u{E000}";

    private string $text;

    /** @var non-empty-string */
    private string $nonce;

    /** @var positive-int */
    private int $keys;

    /**
     * @param non-empty-string $nonce
     * @param positive-int $keys how many keys carry a mark
     */
    public function __construct(string $text, string $nonce, int $keys)
    {
        $this->text = $text;
        $this->nonce = $nonce;
        $this->keys = $keys;
    }

    /**
     * The mark of the key with the number.
     */
    public static function mark(string $nonce, int $number): string
    {
        return self::MARK . $nonce . $number . self::MARK;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * What symfony/yaml read of the text without the marks; null unless each mark is a key exactly once and beside no
     * key alike. The text holds each mark once and no document holds another, so a mark read anywhere else (in a
     * string, nowhere) leaves its key unread.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>|null
     */
    public function restore(array $decoded): ?array
    {
        $found = [];
        $restored = $this->unmarked($decoded, $found);

        return $restored !== null && count($found) === $this->keys ? $restored : null;
    }

    /**
     * A message about the text without the marks.
     */
    public function withoutMarks(string $message): string
    {
        return (string) preg_replace('/' . self::MARK . $this->nonce . '\d+' . self::MARK . '/u', '', $message);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<int|string, true> $found the marks met
     *
     * @return array<array-key, mixed>|null
     */
    private function unmarked(array $value, array &$found): ?array
    {
        $restored = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/^' . self::MARK . $this->nonce . '(\d+)' . self::MARK . '(.*)$/Dsu', $key, $mark) === 1) {
                if (isset($found[$mark[1]]) || array_key_exists($mark[2], $value) || array_key_exists($mark[2], $restored)) {
                    return null;
                }

                $found[$mark[1]] = true;
                $key = $mark[2];
            }

            if (is_array($item)) {
                $item = $this->unmarked($item, $found);
                if ($item === null) {
                    return null;
                }
            }

            $restored[$key] = $item;
        }

        return $restored;
    }
}
