<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveDuplicatedReturnSelfDocblockRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withAutoloadPaths([__DIR__ . '/vendor/autoload.php'])
    ->withoutParallel()
    // The package runs on 7.4: Rector must never upgrade sources past it.
    ->withPhpVersion(PhpVersion::PHP_74)
    ->withPhpSets(php74: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip([
        NewlineAfterStatementRector::class,
        CatchExceptionNameMatchingTypeRector::class,
        // `@return static` on AbstractEnum factories is what types subclasses; it is not redundant with `: self`.
        RemoveDuplicatedReturnSelfDocblockRector::class,
    ])
;
