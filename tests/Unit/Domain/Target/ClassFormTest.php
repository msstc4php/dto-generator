<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\ClassForm;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Domain\Target\WitherStyle;
use PHPUnit\Framework\TestCase;

final class ClassFormTest extends TestCase
{
    /**
     * @dataProvider forms
     */
    public function testFollowsTheShapeTableOfTheSpec(string $php, string $mutability, string $accessors, string $expected): void
    {
        $target = $this->target($php, $mutability, $accessors);

        self::assertSame($expected, $this->summary($target->classFormFor(Mutability::from($mutability))));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function forms(): array
    {
        return [
            '7.4 immutable' => ['7.4', Mutability::IMMUTABLE, AccessorStyle::AUTO, 'declared private getters withers:clone-assign'],
            '7.4 mutable getters' => ['7.4', Mutability::MUTABLE, AccessorStyle::GETTERS, 'declared private getters setters withers:none'],
            '7.4 mutable public' => ['7.4', Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES, 'declared public withers:none'],
            '8.0 immutable' => ['8.0', Mutability::IMMUTABLE, AccessorStyle::AUTO, 'promoted private getters withers:clone-assign'],
            '8.1 immutable' => ['8.1', Mutability::IMMUTABLE, AccessorStyle::AUTO, 'promoted public readonly-properties withers:new-self'],
            '8.1 immutable getters' => ['8.1', Mutability::IMMUTABLE, AccessorStyle::GETTERS, 'promoted private readonly-properties getters withers:new-self'],
            '8.1 mutable' => ['8.1', Mutability::MUTABLE, AccessorStyle::AUTO, 'promoted private getters setters withers:none'],
            '8.2 immutable' => ['8.2', Mutability::IMMUTABLE, AccessorStyle::AUTO, 'promoted public readonly-class withers:new-self'],
            '8.4 immutable getters' => ['8.4', Mutability::IMMUTABLE, AccessorStyle::GETTERS, 'promoted private readonly-class getters withers:new-self'],
            '8.5 immutable' => ['8.5', Mutability::IMMUTABLE, AccessorStyle::AUTO, 'promoted public readonly-class withers:clone-with'],
            '8.5 mutable public' => ['8.5', Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES, 'promoted public withers:none'],
        ];
    }

    public function testFollowsThePerClassMutability(): void
    {
        $target = $this->target('8.2', Mutability::IMMUTABLE, AccessorStyle::AUTO);

        self::assertSame('promoted private getters setters withers:none', $this->summary($target->classFormFor(Mutability::from(Mutability::MUTABLE))));
    }

    private function target(string $php, string $mutability, string $accessors): TargetProfile
    {
        return new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
    }

    private function summary(ClassForm $form): string
    {
        return implode(' ', array_filter([
            $form->isPromoted() ? 'promoted' : 'declared',
            $form->hasPublicProperties() ? 'public' : 'private',
            $form->hasReadonlyProperties() ? 'readonly-properties' : '',
            $form->isReadonlyClass() ? 'readonly-class' : '',
            $form->hasGetters() ? 'getters' : '',
            $form->hasSetters() ? 'setters' : '',
            'withers:' . $form->withers()->value(),
        ], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @dataProvider impossibleForms
     */
    public function testRejectsImpossibleShapes(callable $create, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return array<string, array{callable(): ClassForm, string}>
     */
    public static function impossibleForms(): array
    {
        $newSelf = WitherStyle::from(WitherStyle::NEW_SELF);

        return [
            'readonly declared properties' => [static fn (): ClassForm => ClassForm::immutable(false, true, true, false, $newSelf), 'Readonly properties are always promoted'],
            'readonly twice' => [static fn (): ClassForm => ClassForm::immutable(true, true, true, true, $newSelf), 'either the class or its properties'],
            'immutable without withers' => [static fn (): ClassForm => ClassForm::immutable(true, true, false, true, WitherStyle::from(WitherStyle::NONE)), 'An immutable class needs withers'],
        ];
    }
}
