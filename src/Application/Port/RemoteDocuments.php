<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;

/**
 * Documents a remote $ref names (spec F2): read from the cache of the config, fetched into it when allowed.
 */
interface RemoteDocuments
{
    /**
     * @param string $url a normalized http(s) URL that the settings allow
     * @param bool $fetch whether a document missing from the cache may be fetched and stored
     *
     * @throws DocumentLoadFailed
     */
    public function load(string $url, RemoteRefsSettings $settings, bool $fetch): Document;
}
