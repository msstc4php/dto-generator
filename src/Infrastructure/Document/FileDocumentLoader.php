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
    // Without aliases a document holds fewer values than bytes; a few anchors stay far below ten times that, while a
    // kilobyte of nested aliases expands to millions of values that every later stage would walk.
    private const MAX_VALUES_PER_BYTE = 10;

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

        $limit = self::MAX_VALUES_PER_BYTE * strlen($content);
        if ($extension !== 'json' && self::exceeds($decoded, $limit)) {
            throw DocumentLoadFailed::malformed($path, sprintf('its YAML aliases expand to more than %d values.', self::MAX_VALUES_PER_BYTE * strlen($content)));
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
