<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerificationFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltEnum;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * verifyClasses for one run: every class and constant a rendered attribute names must exist for the consumer, unless
 * this run generates it. Each missing name is reported once, where it is first met, whichever attribute uses it.
 */
final class AttributeVerification
{
    private ClassVerifier $verifier;

    private Diagnostics $diagnostics;

    /** @var array<string, true> lower-cased FQCN of the generated classes and enums */
    private array $generated = [];

    /** @var array<string, true> "lower-cased enum FQCN::CASE" */
    private array $cases = [];

    /** @var array<string, true> */
    private array $reported = [];

    private bool $failed = false;

    /**
     * @param list<BuiltClass> $classes
     * @param list<BuiltEnum> $enums
     */
    public function __construct(ClassVerifier $verifier, array $classes, array $enums, Diagnostics $diagnostics)
    {
        $this->verifier = $verifier;
        $this->diagnostics = $diagnostics;
        foreach ($classes as $built) {
            $this->generated[$this->key($built->model()->name())] = true;
        }

        foreach ($enums as $built) {
            $enum = $built->model();
            $this->generated[$this->key($enum->name())] = true;
            foreach ($enum->cases() as $case) {
                $this->cases[$this->key($enum->name()) . '::' . $case->name()] = true;
            }
        }
    }

    /**
     * @param list<AttributeModel> $attributes
     */
    public function verify(array $attributes, SchemaLocation $at): void
    {
        if ($this->failed) {
            return;
        }

        try {
            foreach ($attributes as $attribute) {
                $name = $attribute->className();
                foreach ($attribute->arguments() as $argument) {
                    foreach ($this->missing($argument->value()) as $key => $missing) {
                        $this->report($key, sprintf('%s, used by attribute %s, does not exist.', $missing, $name->fqcn()), $at);
                    }
                }

                if (!$this->hasClass($name)) {
                    $this->report($this->key($name), sprintf('Attribute class %s does not exist.', $name->fqcn()), $at);
                }
            }
        } catch (ClassVerificationFailed $exception) {
            $this->failed = true;
            $this->diagnostics->error($exception->getMessage(), $at);
        }
    }

    /**
     * @return array<string, string> what the value names and the consumer lacks, like "Class App\X" or
     *                               "Constant App\X::Y", by a key that ignores the case of class names, as PHP does:
     *                               "app\x", "app\x::Y", or "\Y" for a global constant
     *
     * @throws ClassVerificationFailed
     */
    private function missing(ArgumentValue $value): array
    {
        $missing = [];
        switch ($value->kind()) {
            case ArgumentValue::KIND_CONSTANT:
                $class = $value->constantClass();
                if (!$this->hasConstant($class, $value->constantName())) {
                    $key = $class instanceof ClassName ? $this->key($class) . '::' . $value->constantName() : '\\' . $value->constantName();
                    $missing[$key] = 'Constant ' . ($class instanceof ClassName ? $class->fqcn() . '::' : '') . $value->constantName();
                }

                break;
            case ArgumentValue::KIND_CLASS_REFERENCE:
                if (!$this->hasType($value->className())) {
                    $missing[$this->key($value->className())] = 'Class ' . $value->className()->fqcn();
                }

                break;
            case ArgumentValue::KIND_NEW_INSTANCE:
                if (!$this->hasClass($value->className())) {
                    $missing[$this->key($value->className())] = 'Class ' . $value->className()->fqcn();
                }

                break;
        }

        foreach ($value->children() as $child) {
            $missing += $this->missing($child);
        }

        return $missing;
    }

    /**
     * @throws ClassVerificationFailed
     */
    private function hasClass(ClassName $class): bool
    {
        return isset($this->generated[$this->key($class)]) || $this->verifier->hasClass($class);
    }

    /**
     * @throws ClassVerificationFailed
     */
    private function hasType(ClassName $class): bool
    {
        return isset($this->generated[$this->key($class)]) || $this->verifier->hasType($class);
    }

    /**
     * @throws ClassVerificationFailed
     */
    private function hasConstant(?ClassName $class, string $name): bool
    {
        if ($class instanceof ClassName && isset($this->generated[$this->key($class)])) {
            return isset($this->cases[$this->key($class) . '::' . $name]);
        }

        return $this->verifier->hasConstant($class, $name);
    }

    /**
     * @param string $key the missing name, which is reported once whichever attribute uses it
     */
    private function report(string $key, string $message, SchemaLocation $at): void
    {
        if (!isset($this->reported[$key])) {
            $this->reported[$key] = true;
            $this->diagnostics->error($message, $at);
        }
    }

    // PHP resolves class names without case.
    private function key(ClassName $class): string
    {
        return Identifier::asciiLower($class->fqcn());
    }
}
