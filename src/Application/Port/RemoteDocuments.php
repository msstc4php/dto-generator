<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

/**
 * Documents a remote $ref names (spec F2): read from the cache of the config, fetched into it when allowed.
 */
interface RemoteDocuments
{
    /**
     * @param string $url a normalized http(s) URL the config allows
     * @param string $cacheDir absolute directory of the cache
     * @param positive-int $timeout seconds the fetch of the document may take
     * @param bool $fetch whether a document missing from the cache may be fetched and stored
     *
     * @throws DocumentLoadFailed
     */
    public function load(string $url, string $cacheDir, int $timeout, bool $fetch): Document;
}
