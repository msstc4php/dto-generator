<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

/**
 * Documents a remote $ref names (spec F2, F3): read from the cache of the config, fetched into it when allowed.
 */
interface RemoteDocuments
{
    /**
     * @param string $url a normalized http(s) URL the config allows
     *
     * @throws DocumentLoadFailed
     */
    public function load(string $url, RemoteRequest $request): Document;
}
