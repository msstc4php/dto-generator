<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Remote;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private const NAME = 'DTO_GENERATOR_ENVIRONMENT_TEST';

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME], $_ENV[self::NAME]);
        putenv(self::NAME);
    }

    public function testPrefersServerThenEnvThenGetenv(): void
    {
        self::assertNull(Environment::variable(self::NAME));

        putenv(self::NAME . '=process');
        self::assertSame('process', Environment::variable(self::NAME));

        $_ENV[self::NAME] = 'dotenv';
        self::assertSame('dotenv', Environment::variable(self::NAME));

        $_SERVER[self::NAME] = 'server';
        self::assertSame('server', Environment::variable(self::NAME));
    }

    public function testSkipsWhatIsNoString(): void
    {
        $_SERVER[self::NAME] = ['not', 'a', 'string'];
        $_ENV[self::NAME] = 'dotenv';

        self::assertSame('dotenv', Environment::variable(self::NAME));
    }
}
