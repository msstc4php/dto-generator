<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use JsonException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * JSON or YAML text to the decoded document, for files and remote documents alike.
 */
final class DocumentDecoder
{
    // Without aliases a document holds fewer values than bytes. Anchors reused across a spec stay below ten values per
    // byte or a million values, which later stages walk in seconds; a kilobyte of nested aliases expands to millions.
    private const MAX_VALUES_PER_BYTE = 10;

    private const MAX_VALUES_OF_ANY_SIZE = 1000000;

    private const REWRITTEN = ' Keys written after "?" were rewritten first, so the text quoted is the rewritten one.';

    private ?YamlCache $cache;

    public function __construct(?YamlCache $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * @param bool $json JSON, else YAML
     *
     * @return array<array-key, mixed>
     *
     * @throws UndecodableDocument
     */
    public function decode(string $content, bool $json): array
    {
        // Editors on Windows prepend a UTF-8 BOM, which neither decoder accepts as whitespace.
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = (string) substr($content, 3);
        }

        $cache = $json ? null : $this->cache;
        $cached = $cache instanceof YamlCache ? $cache->get($content) : null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $decoded = $json ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : $this->yaml($content);
        } catch (JsonException|ParseException $exception) {
            throw UndecodableDocument::malformed($exception->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && Json::isList($decoded))) {
            throw UndecodableDocument::notAnObject();
        }

        if ($cache instanceof YamlCache) {
            $cache->put($content, $decoded);
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @throws UndecodableDocument
     */
    private function checkSize(array $decoded, string $content): void
    {
        $limit = max(self::MAX_VALUES_PER_BYTE * strlen($content), self::MAX_VALUES_OF_ANY_SIZE);
        $budget = $limit;
        if (self::exceeds($decoded, $budget)) {
            throw UndecodableDocument::malformed(sprintf('its YAML aliases expand to more than %d values.', $limit));
        }
    }

    /**
     * @return mixed
     *
     * @throws ParseException
     * @throws UndecodableDocument
     */
    private function yaml(string $content)
    {
        try {
            $decoded = $this->parse($content);
        } catch (ParseException $exception) {
            return $this->withKeysAfterQuestionMarks($content, $exception);
        }

        if (is_array($decoded)) {
            $this->checkSize($decoded, $content);
        }

        return $decoded;
    }

    /**
     * symfony/yaml reads no key after "?", which generators write for long keys (Twilio); it fails on them with one
     * message or another. A text it would read otherwise than it means stays rejected with the first error.
     *
     * @return array<array-key, mixed>
     *
     * @throws ParseException
     * @throws UndecodableDocument
     */
    private function withKeysAfterQuestionMarks(string $content, ParseException $exception): array
    {
        $rewritten = preg_match('/^[ \t]*\?[ \t]/m', $content) === 1 ? ExplicitYamlKeys::rewrite($content) : null;
        if (!$rewritten instanceof RewrittenYaml) {
            throw $exception;
        }

        try {
            $decoded = $this->parse($rewritten->text());
        } catch (ParseException $failure) {
            throw UndecodableDocument::malformed($rewritten->withoutMarks($failure->getMessage()) . self::REWRITTEN);
        }

        if (!is_array($decoded)) {
            throw $exception;
        }

        // Taking the marks off walks every value an alias repeats: the size is checked first.
        $this->checkSize($decoded, $content);
        $restored = $rewritten->restore($decoded);
        if ($restored === null) {
            throw $exception;
        }

        return $restored;
    }

    /**
     * @return mixed
     *
     * @throws ParseException
     */
    private function parse(string $yaml)
    {
        // Without PARSE_OBJECT symfony/yaml never instantiates "!php/object" tags.
        return Yaml::parse($yaml);
    }

    /**
     * Counts down the values it visits and stops as soon as the budget runs out, so an alias bomb costs no more than
     * the budget.
     *
     * @param array<array-key, mixed> $value
     */
    private static function exceeds(array $value, int &$budget): bool
    {
        foreach ($value as $item) {
            $budget--;
            if ($budget < 0 || (is_array($item) && self::exceeds($item, $budget))) {
                return true;
            }
        }

        return false;
    }
}
