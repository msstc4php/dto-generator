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
            $decoded = $json ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : $this->yaml($content);
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
     * @return mixed
     *
     * @throws ParseException
     */
    private function yaml(string $content)
    {
        try {
            // Without PARSE_OBJECT symfony/yaml never instantiates "!php/object" tags.
            return Yaml::parse($content);
        } catch (ParseException $exception) {
            // symfony/yaml reads no key after "?", which generators write for long keys (Twilio).
            if (strpos($exception->getMessage(), 'Complex mappings are not supported') === false) {
                throw $exception;
            }

            return Yaml::parse($this->withImplicitKeys($content));
        }
    }

    /**
     * The YAML with every scalar key written after "?" written before its ":" instead; block scalars stay as they are.
     */
    private function withImplicitKeys(string $content): string
    {
        $lines = explode("\n", $content);
        $written = [];
        $block = null;
        $counter = count($lines);
        for ($index = 0; $index < $counter; $index++) {
            $line = $lines[$index];
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($block !== null && (trim($line) === '' || $indent > $block)) {
                $written[] = $line;

                continue;
            }

            $block = null;
            $next = $lines[$index + 1] ?? null;
            if ($next !== null && preg_match('/^( *)\? ([^\[{].*)$/D', $line, $key) === 1
                && preg_match('/^' . $key[1] . ':(?: (.*))?$/D', $next, $value) === 1) {
                $name = trim($key[2]);
                $written[] = $key[1] . (in_array($name[0], ['"', "'"], true) ? $name : (string) json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . ':';
                if (($value[1] ?? '') !== '') {
                    $written[] = $key[1] . '  ' . $value[1];
                }

                $index++;

                continue;
            }

            if (preg_match('/[:-] *[|>][-+0-9]* *(?:#.*)?$/D', $line) === 1) {
                $block = $indent;
            }

            $written[] = $line;
        }

        return implode("\n", $written);
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
