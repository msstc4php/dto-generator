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

        try {
            // Without PARSE_OBJECT symfony/yaml never instantiates "!php/object" tags.
            $decoded = $json ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($content);
        } catch (JsonException|ParseException $exception) {
            throw UndecodableDocument::malformed($exception->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && Json::isList($decoded))) {
            throw UndecodableDocument::notAnObject();
        }

        $limit = max(self::MAX_VALUES_PER_BYTE * strlen($content), self::MAX_VALUES_OF_ANY_SIZE);
        $budget = $limit;
        if (!$json && self::exceeds($decoded, $budget)) {
            throw UndecodableDocument::malformed(sprintf('its YAML aliases expand to more than %d values.', $limit));
        }

        return $decoded;
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
