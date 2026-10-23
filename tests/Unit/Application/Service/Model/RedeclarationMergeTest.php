<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class RedeclarationMergeTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const PET = ['$ref' => '#/components/schemas/Pet'];

    private const META = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    private const MERGED = 'warning ' . self::AT . 'Cat/allOf/1/properties/p: Property "p" is declared again with a type PHP lets no subclass declare, so the members are merged instead of extending App\Dto\Pet; "x-php-all-of: merge" says so, "extends" keeps the error.';

    private const KEPT = 'error ' . self::AT . 'Cat/allOf/1/properties/p: Property "p" of App\Dto\Cat is already declared by App\Dto\Pet.';

    /**
     * @dataProvider merged
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     */
    public function testMergesAClassThatRedeclaresAPropertyPhpCannot(array $parent, array $child, bool $required, string $type): void
    {
        $output = $this->build($parent, $child, $required);

        self::assertSame([self::MERGED], ModelFixture::messages($output));
        self::assertSame('final', ModelFixture::hierarchy($output)['App\Dto\Cat']);
        self::assertSame('final', ModelFixture::hierarchy($output)['App\Dto\Pet']);
        self::assertSame(['id: int|null', 'p: ' . $type, 'lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool, string}>
     */
    public static function merged(): iterable
    {
        yield 'a date over a string' => [['type' => 'string'], ['type' => 'string', 'format' => 'date-time'], false, 'DateTimeImmutable|null'];
        yield 'an int over a number' => [['type' => 'number'], ['type' => 'integer'], false, 'int|null'];
        yield 'a class over an object' => [['type' => 'object'], ['$ref' => '#/components/schemas/Meta'], false, 'App\Dto\Meta|null'];
        yield 'a list of classes over a list of objects' => [['type' => 'array', 'items' => ['type' => 'object']], ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Meta']], false, 'list<App\Dto\Meta>|null'];
        yield 'a list of narrower items' => [['type' => 'array'], ['type' => 'array', 'items' => ['type' => 'string']], false, 'list<string>|null'];
        yield 'the values both admit' => [['type' => ['integer', 'string']], ['type' => ['string', 'boolean']], false, 'string|null'];
        yield 'a property the parent requires' => [['type' => 'string'], ['type' => 'string', 'format' => 'date'], true, 'DateTimeImmutable'];
    }

    /**
     * @dataProvider unmerged
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     */
    public function testKeepsTheErrorOfARedeclarationAMergeCouldNotJoin(array $parent, array $child): void
    {
        $output = $this->build($parent, $child, false);

        self::assertSame([self::KEPT], ModelFixture::messages($output));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function unmerged(): iterable
    {
        yield 'another default' => [['type' => 'string', 'default' => 'a'], ['type' => 'string', 'default' => 'b']];
        yield 'a default the parent lacks' => [['type' => 'string'], ['type' => 'string', 'default' => 'b']];
        yield 'another name' => [['type' => 'string'], ['type' => 'string', 'x-php-name' => 'q']];
        yield 'no common values' => [['type' => 'string'], ['type' => 'integer']];
        yield 'an enum of other values' => [['type' => 'string', 'enum' => ['a', 'b']], ['type' => 'string', 'enum' => ['c']]];
        yield 'an enum of other ints' => [['type' => 'string', 'enum' => ['1']], ['type' => 'integer', 'enum' => [1, 2]]];
    }

    public function testKeepsTheParentsTypeWhereTheChildsSaysNothingMore(): void
    {
        $output = $this->build(['type' => 'array', 'items' => ['type' => 'object']], ['items' => ['$ref' => '#/components/schemas/Meta']], false);

        self::assertSame(
            ['warning ' . self::AT . 'Cat/allOf/1/properties/p: Property "p" of App\Dto\Cat shares values with the one App\Dto\Pet declares, whose type list<array<array-key, mixed>>|null the class keeps.'],
            ModelFixture::messages($output),
        );
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    public function testNamesTheFirstPropertyThatAsksForTheMerge(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['p' => ['type' => 'object'], 'q' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta'], 'q' => ['type' => 'string', 'format' => 'date']]]]],
            'Meta' => self::META,
        ]);

        self::assertSame([self::MERGED], ModelFixture::messages($output));
        self::assertSame(['p: App\Dto\Meta|null', 'q: DateTimeImmutable|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testMergesEveryAncestorAndStaysTheParentOfItsSubclassesInAnyOrder(): void
    {
        $kitten = ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['q' => ['type' => 'integer']]]]];
        $cat = ['allOf' => [self::PET, ['required' => ['s'], 'properties' => ['p' => ['type' => ['string', 'boolean']]]]]];
        $pet = ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['p' => ['type' => ['integer', 'string']]]]]];
        $animal = ['type' => 'object', 'properties' => ['s' => ['type' => 'string']]];

        foreach ([['Kitten' => $kitten, 'Cat' => $cat, 'Pet' => $pet, 'Animal' => $animal], ['Animal' => $animal, 'Pet' => $pet, 'Cat' => $cat, 'Kitten' => $kitten]] as $schemas) {
            $output = ModelFixture::build($schemas);

            self::assertSame([self::MERGED], ModelFixture::messages($output));
            self::assertSame('final extends App\Dto\Cat', ModelFixture::hierarchy($output)['App\Dto\Kitten']);
            self::assertSame('open', ModelFixture::hierarchy($output)['App\Dto\Cat']);
            self::assertSame(['s: string', 'p: string|null'], ModelFixture::classes($output)['App\Dto\Cat']);
        }
    }

    public function testNumbersAPropertyThatTakesTheNameOfAnInheritedOne(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['p' => ['type' => 'object'], 'user_id' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta'], 'userId' => ['type' => 'string']]]]],
            'Meta' => self::META,
        ]);

        self::assertSame(
            [self::MERGED, 'warning ' . self::AT . 'Cat/allOf/1/properties/userId: Property "userId" becomes $userId, which "user_id" already uses, so it is $userId2; set "x-php-name" to name it, in the document or "propertyNames" of the source.'],
            ModelFixture::messages($output),
        );
    }

    public function testMergesTheReadAndTheWriteModelAlike(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true], 'p' => ['type' => 'object']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]],
            'Meta' => self::META,
        ]);

        $classes = ModelFixture::classes($output);
        self::assertSame(['id: int|null', 'p: App\Dto\Meta|null'], $classes['App\Dto\CatRead']);
        self::assertSame(['p: App\Dto\Meta|null'], $classes['App\Dto\CatWrite']);
        self::assertSame([], array_filter(ModelFixture::messages($output), static fn (string $message): bool => strncmp($message, 'error', 5) === 0));
    }

    public function testNamesThePropertyTheFirstViewFinds(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['p' => ['type' => 'object', 'readOnly' => true], 'q' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta', 'readOnly' => true], 'q' => ['type' => 'string', 'format' => 'date']]]]],
            'Meta' => self::META,
        ]);

        $merged = array_values(array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, 'merged instead') !== false));
        self::assertSame([str_replace('App\Dto\Pet;', 'App\Dto\PetRead;', self::MERGED)], $merged);
    }

    public function testFollowsAnExplicitMergeWithoutAWarning(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['p' => ['type' => 'object']]],
            'Cat' => ['x-php-all-of' => 'merge', 'allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]],
            'Meta' => self::META,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['p: App\Dto\Meta|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testMergesAClassOfAnotherMutability(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'x-dto-mutable' => true, 'properties' => ['p' => ['type' => 'object']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]],
            'Meta' => self::META,
        ]);

        self::assertSame([self::MERGED], ModelFixture::messages($output));
    }

    public function testMergesTheBaseOfAUnion(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'p' => ['type' => 'object']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]], 'discriminator' => ['propertyName' => 'kind']],
            'Kitten' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['age' => ['type' => 'integer']]]]],
            'Meta' => self::META,
        ]);

        self::assertSame([self::MERGED], ModelFixture::messages($output));
        self::assertSame('abstract by kind {Kitten: App\Dto\Kitten}', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    /**
     * @dataProvider kept
     *
     * @param array<string, array<array-key, mixed>> $schemas
     */
    public function testKeepsTheParentTheDocumentAsksFor(array $schemas): void
    {
        $output = ModelFixture::build($schemas + [
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'p' => ['type' => 'object']]],
            'Meta' => self::META,
        ]);

        self::assertContains(self::KEPT, ModelFixture::messages($output));
        self::assertSame([], array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, 'merged instead') !== false));
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>}>
     */
    public static function kept(): iterable
    {
        $cat = ['allOf' => [self::PET, ['required' => ['kind'], 'properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]];

        yield 'x-php-all-of: extends' => [['Cat' => ['x-php-all-of' => 'extends'] + $cat]];
    }

    public function testLeavesOutTheInlineClassOfTheTypeAVariantCannotTake(): void
    {
        $animal = ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']];
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'p' => ['type' => 'object'], 'q' => ['type' => 'string', 'default' => 'a']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['p' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'q' => ['type' => 'string', 'default' => 'b']]]]],
            'Animal' => $animal,
        ]);

        self::assertArrayNotHasKey('App\\Dto\\CatP', ModelFixture::classes($output));
        self::assertContains('error ' . self::AT . 'Cat/allOf/1/properties/q: Property "q" of App\\Dto\\Cat is already declared by App\\Dto\\Pet.', ModelFixture::messages($output));
    }

    public function testKeepsTheParentOfAVariantInEveryViewAndSaysWhatItDoesNotRequire(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'links' => ['$ref' => '#/components/schemas/Links']]],
            'Links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            'CatLinks' => ['allOf' => [['$ref' => '#/components/schemas/Links'], ['properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]]]],
            'Cat' => ['x-php-all-of' => 'extends', 'allOf' => [self::PET, ['required' => ['links'], 'properties' => ['links' => ['$ref' => '#/components/schemas/CatLinks']]]]],
            'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
        ]);

        $messages = ModelFixture::messages($output);
        self::assertSame([], array_values(array_filter($messages, static fn (string $message): bool => strncmp($message, 'error', 5) === 0)));
        self::assertCount(1, array_filter($messages, static fn (string $message): bool => strpos($message, 'is a variant, which keeps its parent, so it keeps App\\Dto\\Links|null and does not require it.') !== false));
        self::assertSame(['kind: string|null', 'links: App\\Dto\\Links|null'], ModelFixture::classes($output)['App\\Dto\\Pet']);
    }

    public function testSaysWhenAVariantDeclaresATypeWithNoValueInCommon(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'n' => ['type' => 'integer']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['n' => ['type' => 'string']]]]],
            'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
        ]);

        self::assertContains(
            'warning ' . self::AT . 'Cat/allOf/1/properties/n: Property "n" of App\\Dto\\Cat is string|null, which has no value in common with the type App\\Dto\\Pet declares, unless the two are classes of different read or write models; the class is a variant, which keeps its parent, so it keeps int|null.',
            ModelFixture::messages($output),
        );
    }

    /**
     * @dataProvider variants
     *
     * @param array<string, array<array-key, mixed>> $schemas
     */
    public function testKeepsTheTypeOfTheParentOfAVariant(array $schemas, string $at): void
    {
        $output = ModelFixture::build($schemas + [
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'p' => ['type' => 'object']]],
            'Meta' => self::META,
        ]);

        self::assertSame(
            ['warning ' . self::AT . $at . ': Property "p" of App\\Dto\\Cat is App\\Dto\\Meta|null, which PHP lets no subclass of App\\Dto\\Pet declare; the class is a variant, which keeps its parent, so it keeps array<array-key, mixed>|null.'],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, 'is a variant') !== false)),
        );
        self::assertNotContains('p: App\\Dto\\Meta|null', ModelFixture::classes($output)['App\\Dto\\Cat']);
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>, string}>
     */
    public static function variants(): iterable
    {
        $cat = ['allOf' => [self::PET, ['required' => ['kind'], 'properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]];

        yield 'a variant' => [['Cat' => $cat, 'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']]], 'Cat/allOf/1/properties/p'];
        yield 'a subclass of a variant' => [[
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Feline'], ['properties' => ['p' => ['$ref' => '#/components/schemas/Meta']]]]],
            'Feline' => ['allOf' => [self::PET, ['required' => ['kind'], 'properties' => ['claws' => ['type' => 'boolean']]]]],
            'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Feline']], 'discriminator' => ['propertyName' => 'kind']],
        ], 'Cat/allOf/1/properties/p'];
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     */
    private function build(array $parent, array $child, bool $required): Output
    {
        return ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'p' => $parent]] + ($required ? ['required' => ['p']] : []),
            'Cat' => ['allOf' => [self::PET, ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer'], 'p' => $child]]]],
            'Meta' => self::META,
        ]);
    }
}
