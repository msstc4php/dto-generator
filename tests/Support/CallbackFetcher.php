<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use Closure;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Fetcher;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Response;

/**
 * Answers through a closure, which may do whatever else should happen meanwhile.
 */
final class CallbackFetcher implements Fetcher
{
    /** @var Closure(string): Response */
    private Closure $answer;

    /**
     * @param Closure(string): Response $answer
     */
    public function __construct(Closure $answer)
    {
        $this->answer = $answer;
    }

    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        return ($this->answer)($url);
    }
}
