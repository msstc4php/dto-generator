<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

/**
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
     * JSON Schema keywords the extension checks (such as `contains`), so the core does not warn that they have no effect.
     * Only keywords the core warns about may be claimed. The extension warns itself where it cannot check one.
     */
    public function claimKeywords(string ...$keywords): void;
}
