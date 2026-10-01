<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function testCollectsInOrderAndKnowsAboutErrors(): void
    {
        $diagnostics = new Diagnostics();
        $diagnostics->warning('Looks odd.');

        self::assertFalse($diagnostics->hasErrors());

        $location = new SchemaLocation('a.yaml', '/x');
        $diagnostics->error('Broken.', $location);

        self::assertTrue($diagnostics->hasErrors());
        self::assertCount(2, $diagnostics);
        self::assertSame(
            ['warning: Looks odd.', 'error a.yaml#/x: Broken.'],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all()),
        );

        $error = $diagnostics->errors()[0];
        self::assertCount(1, $diagnostics->errors());
        self::assertSame($location, $error->location());
        self::assertTrue($error->severity()->isError());
        self::assertFalse($diagnostics->all()[0]->severity()->isError());
        self::assertSame('Broken.', $error->message());
    }

    public function testMergesAnotherCollector(): void
    {
        $first = new Diagnostics();
        $first->warning('a');

        $second = new Diagnostics();
        $second->error('b');

        $first->merge($second);

        self::assertSame(['a', 'b'], array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message(), $first->all()));
    }

    public function testRejectsABlankMessage(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('needs a message');

        new Diagnostic(Severity::from(Severity::ERROR), '  ');
    }
}
