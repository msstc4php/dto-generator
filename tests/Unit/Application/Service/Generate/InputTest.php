<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Generate;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testWritesWhereTheConfigSaysAndFetchesByDefault(): void
    {
        $input = new Input('/project/dto-generator.yaml', Mode::from(Mode::WRITE));

        self::assertNull($input->outputRoot());
        self::assertTrue($input->fetchesRemote());
    }

    public function testFetchesOnlyWhenItWrites(): void
    {
        self::assertFalse((new Input('/p/c.yaml', Mode::from(Mode::CHECK)))->fetchesRemote());
        self::assertFalse((new Input('/p/c.yaml', Mode::from(Mode::DRY_RUN)))->fetchesRemote());
    }

    public function testTakesAnOutputRootWithoutChangingItself(): void
    {
        $input = new Input('/project/dto-generator.yaml', Mode::from(Mode::CHECK));
        $moved = $input->withOutputRoot('/build/dto');

        self::assertNull($input->outputRoot());
        self::assertSame('/build/dto', $moved->outputRoot());
        self::assertSame('/project/dto-generator.yaml', $moved->configPath());
        self::assertSame(Mode::CHECK, $moved->mode()->value());
    }

    public function testNormalizesTheOutputRoot(): void
    {
        self::assertSame('/build/dto', (new Input('/p/c.yaml', Mode::from(Mode::WRITE)))->withOutputRoot('/build/./x/../dto/')->outputRoot());
    }

    public function testRefusesARelativeOutputRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The output root "var/dto" must be an absolute path.');

        (new Input('/p/c.yaml', Mode::from(Mode::WRITE)))->withOutputRoot('var/dto');
    }

    public function testStopsFetchingWithoutChangingItself(): void
    {
        $input = (new Input('/p/c.yaml', Mode::from(Mode::WRITE)))->withOutputRoot('/build');
        $offline = $input->withoutFetching();

        self::assertTrue($input->fetchesRemote());
        self::assertFalse($offline->fetchesRemote());
        self::assertSame('/build', $offline->outputRoot());
        self::assertSame(Mode::WRITE, $offline->mode()->value());
        self::assertFalse($offline->withOutputRoot('/other')->fetchesRemote());
    }

    public function testRefreshesOnlyWhenAskedAndWhenItFetches(): void
    {
        $input = new Input('/p/c.yaml', Mode::from(Mode::WRITE));
        $refreshing = $input->withOutputRoot('/build')->withRefreshedRemote();

        self::assertFalse($input->refreshesRemote());
        self::assertTrue($refreshing->refreshesRemote());
        self::assertSame('/build', $refreshing->outputRoot());
        self::assertFalse($refreshing->withoutFetching()->refreshesRemote());
        self::assertFalse((new Input('/p/c.yaml', Mode::from(Mode::CHECK)))->withRefreshedRemote()->refreshesRemote());
    }
}
