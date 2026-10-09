<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A plugin of the generator (spec §8), created without arguments and configured from `extensionConfig.<name>`.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @api
 */
interface Extension
{
    /**
     * @return non-empty-string the key of its section in `extensionConfig`
     */
    public function name(): string;

    /**
     * @param array<int|string, JsonValue> $config its `extensionConfig` section as written, empty when absent
     */
    public function register(ExtensionRegistry $registry, array $config): void;
}
