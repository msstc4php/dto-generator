<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Buffered stdout and stderr, as a console output has them. CommandTester can split them too, but symfony/console 5.4
 * does it through ReflectionProperty::setAccessible(), deprecated on PHP 8.5.
 */
final class SplitOutput extends BufferedOutput implements ConsoleOutputInterface
{
    public BufferedOutput $errors;

    public function __construct()
    {
        parent::__construct();
        $this->errors = new BufferedOutput();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
    }

    public function section(): ConsoleSectionOutput
    {
        throw new RuntimeException('No sections here.');
    }
}
