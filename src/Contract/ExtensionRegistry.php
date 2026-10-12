<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

/**
 * The generator implements it and hands it to extensions; extensions do not implement it.
 *
 * @api
 */
interface ExtensionRegistry
{
    public function addPropertyEnricher(PropertyEnricher $enricher): void;

    public function addClassEnricher(ClassEnricher $enricher): void;

    /**
     * Two extensions registering one format is an error; a format in the config overrides an extension's.
     */
    public function addFormat(string $format, FormatMapping $mapping): void;

    /**
     * Keys like `x-assert-*` that the extension reads, so the core does not treat them as unknown.
     */
    public function claimExtensionKeys(string ...$globs): void;

    /**
     * JSON Schema keywords the extension checks (such as `contains`) among those the core calls unsupported. Each
     * occurrence waits for the extension to mark it in the context's keywordChecks(), as checked or warned about; the
     * core warns about the rest after enrichment, as for keywords nobody claims.
     */
    public function claimKeywords(string ...$keywords): void;
}
