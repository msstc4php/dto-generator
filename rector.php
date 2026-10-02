<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Foreach_\ForeachItemsAssignToEmptyArrayToAssignRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveDuplicatedReturnSelfDocblockRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
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
        // Generated code and the attribute classes it uses, checked on each target PHP version instead.
        __DIR__ . '/tests/Targets',
        // Tests name the attribute classes of tests/Targets/attributes.php, which only the generated code loads.
        StringClassNameToClassConstantRector::class => [__DIR__ . '/tests'],
        NewlineAfterStatementRector::class,
        CatchExceptionNameMatchingTypeRector::class,
        // `@return static` on AbstractEnum factories is what types subclasses; it is not redundant with `: self`.
        RemoveDuplicatedReturnSelfDocblockRector::class,
        // The re-indexing loop in listOf() is not a plain copy on PHP 8.0+ (named args reach the variadic).
        ForeachItemsAssignToEmptyArrayToAssignRector::class => [__DIR__ . '/src/Domain/Model/ArgumentValue.php'],
    ])
;
