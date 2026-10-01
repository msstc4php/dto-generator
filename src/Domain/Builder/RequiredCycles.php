<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;

/**
 * Finds required properties whose class needs, through required properties only, an instance of the owner:
 * such a class can never be constructed.
 */
final class RequiredCycles
{
    private function __construct()
    {
    }

    /**
     * @param list<ClassModel> $classes
     */
    public static function check(array $classes, Diagnostics $diagnostics): void
    {
        $edges = self::edges($classes);
        $reachable = array_map(static fn (array $targets): array => self::reachableFrom($edges, $targets), $edges);
        foreach ($classes as $class) {
            $owner = $class->name()->fqcn();
            foreach ($class->properties() as $property) {
                $type = $property->type();
                if (!$property->isRequired() || !$type instanceof ClassType) {
                    continue;
                }

                if (isset($reachable[$type->className()->fqcn()][$owner])) {
                    $diagnostics->warning(
                        sprintf(
                            'Required property "%s" of %s leads back to it through required properties, so no instance can ever be constructed.',
                            $property->wireName(),
                            $owner,
                        ),
                        $property->source(),
                    );
                }
            }
        }
    }

    /**
     * @param list<ClassModel> $classes
     *
     * @return array<string, list<string>> FQCN → FQCNs of its required class-typed properties
     */
    private static function edges(array $classes): array
    {
        $edges = [];
        foreach ($classes as $class) {
            $targets = [];
            foreach ($class->properties() as $property) {
                $type = $property->type();
                if ($property->isRequired() && $type instanceof ClassType) {
                    $targets[] = $type->className()->fqcn();
                }
            }

            $edges[$class->name()->fqcn()] = $targets;
        }

        return $edges;
    }

    /**
     * Every class reachable through at least one required property, starting from the given targets.
     *
     * @param array<string, list<string>> $edges
     * @param list<string> $pending
     *
     * @return array<string, string>
     */
    private static function reachableFrom(array $edges, array $pending): array
    {
        $seen = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (!isset($seen[$current])) {
                $seen[$current] = $current;
                foreach ($edges[$current] ?? [] as $next) {
                    $pending[] = $next;
                }
            }
        }

        return $seen;
    }
}
