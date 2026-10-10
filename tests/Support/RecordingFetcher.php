<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Fetcher;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Response;

/**
 * Answers in the given order and records each request.
 */
final class RecordingFetcher implements Fetcher
{
    /** @var list<Response|FetchFailed> */
    private array $answers;

    /** @var list<array{string, int, int}> */
    private array $requests = [];

    /**
     * @param list<Response|FetchFailed> $answers
     */
    public function __construct(array $answers)
    {
        $this->answers = $answers;
    }

    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        $this->requests[] = [$url, $timeout, $maxBytes];
        $answer = array_shift($this->answers);
        if (!$answer instanceof Response) {
            throw $answer ?? new FetchFailed('no answer left');
        }

        return $answer;
    }

    /**
     * @return list<array{string, int, int}> URL, timeout and size limit of each request
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
