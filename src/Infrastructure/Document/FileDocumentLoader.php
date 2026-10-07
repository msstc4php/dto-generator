<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FileDocumentLoader implements DocumentLoader
{
    // Without aliases a document holds fewer values than bytes. Anchors reused across a spec stay below ten values per
    // byte or a million values, which later stages walk in seconds; a kilobyte of nested aliases expands to millions.
    private const MAX_VALUES_PER_BYTE = 10;

    private const MAX_VALUES_OF_ANY_SIZE = 1000000;

    /** @var array<string, array<array-key, mixed>> decoded content by real path */
    private array $decoded = [];

    public function load(string $path): Document
    {
        $normalized = Path::normalize($path);
        // realpath() throws on NUL bytes; a decoded "%00" in a $ref must stay a load failure.
        if (strpos($normalized, "\0") !== false) {
            throw DocumentLoadFailed::notFound(str_replace("\0", '\0', $normalized));
        }

        $real = realpath($normalized);
        if ($real === false || !is_file($real)) {
            throw DocumentLoadFailed::notFound($normalized);
        }

        $this->decoded[$real] ??= $this->decode($normalized, $real);

        // The requested spelling is the identity, so locations stay lexical and match resolved $refs.
        return new Document($normalized, $this->decoded[$real]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $path, string $real): array
    {
        $extension = Identifier::asciiLower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw DocumentLoadFailed::unsupportedFormat($path);
        }

        $content = is_readable($real) ? file_get_contents($real) : false;
        if ($content === false) {
            throw DocumentLoadFailed::unreadable($path);
        }

        // Editors on Windows prepend a UTF-8 BOM, which neither decoder accepts as whitespace.
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = (string) substr($content, 3);
        }

        try {
            // Without PARSE_OBJECT symfony/yaml never instantiates "!php/object" tags.
            $decoded = $extension === 'json'
                ? json_decode($content, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($content);
        } catch (JsonException|ParseException $exception) {
            throw DocumentLoadFailed::malformed($path, $exception->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && Json::isList($decoded))) {
            throw DocumentLoadFailed::notAnObject($path);
        }

        $limit = max(self::MAX_VALUES_PER_BYTE * strlen($content), self::MAX_VALUES_OF_ANY_SIZE);
        $budget = $limit;
        if ($extension !== 'json' && self::exceeds($decoded, $budget)) {
            throw DocumentLoadFailed::malformed($path, sprintf('its YAML aliases expand to more than %d values.', $limit));
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
