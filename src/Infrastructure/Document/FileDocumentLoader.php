<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FileDocumentLoader implements DocumentLoader
{
    /** @var array<string, array<array-key, mixed>> decoded content by real path */
    private array $decoded = [];

    public function load(string $path): Document
    {
        $normalized = Path::normalize($path);
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

        return $decoded;
    }
}
