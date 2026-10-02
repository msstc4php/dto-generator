<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ErrorOutput
{
    private function __construct()
    {
    }

    /**
     * Stderr of a console, or the output itself when it has none (a buffer, a test).
     */
    public static function of(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
