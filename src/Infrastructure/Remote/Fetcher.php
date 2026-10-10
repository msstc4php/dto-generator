<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

interface Fetcher
{
    /**
     * One GET, redirects not followed.
     *
     * @param positive-int $timeout seconds
     * @param positive-int $maxBytes a longer body is an error
     *
     * @throws FetchFailed when no answer arrives or the body is too long
     */
    public function get(string $url, int $timeout, int $maxBytes): Response;
}
