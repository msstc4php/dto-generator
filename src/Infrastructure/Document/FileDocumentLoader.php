<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class FileDocumentLoader implements DocumentLoader
{
    private DocumentDecoder $decoder;

    /** @var array<string, array<array-key, mixed>> decoded content by real path */
    private array $decoded = [];

    public function __construct(?DocumentDecoder $decoder = null)
    {
        $this->decoder = $decoder ?? new DocumentDecoder();
    }

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

        try {
            return $this->decoder->decode($content, $extension === 'json');
        } catch (UndecodableDocument $exception) {
            throw $exception->isMalformed() ? DocumentLoadFailed::malformed($path, $exception->getMessage()) : DocumentLoadFailed::notAnObject($path);
        }
    }
}
