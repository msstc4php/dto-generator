<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\EnumBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class EnumBuilderTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/E';

    public function testBuildsAStringEnum(): void
    {
        [$enum, $messages] = $this->build([
            'type' => 'string',
            'description' => 'Currency.',
            'enum' => ['EUR', 'in-progress', null],
            'x-enum-descriptions' => ['EUR' => 'Euro.'],
        ]);

        self::assertSame([], $messages);
        self::assertInstanceOf(EnumModel::class, $enum);
        self::assertSame('string', $enum->backing()->value());
        self::assertSame(['EUR' => 'EUR', 'IN_PROGRESS' => 'in-progress'], $this->cases($enum));
        self::assertSame('Euro.', $enum->cases()[0]->doc()->description());
        self::assertSame('Currency.', $enum->doc()->description());
        self::assertSame(['EUR' => 'EUR', 'in-progress' => 'IN_PROGRESS'], EnumType::of($enum)->cases());
    }

    public function testBuildsAnIntegerEnumWithoutAType(): void
    {
        [$enum, $messages] = $this->build(['enum' => [1, -2]]);

        self::assertSame([], $messages);
        self::assertInstanceOf(EnumModel::class, $enum);
        self::assertSame('int', $enum->backing()->value());
        self::assertSame(['VALUE_1' => 1, 'VALUE_MINUS_2' => -2], $this->cases($enum));
    }

    /**
     * @dataProvider problems
     *
     * @param array<array-key, mixed> $schema
     * @param list<string> $messages
     */
    public function testReportsProblems(array $schema, bool $built, array $messages): void
    {
        [$enum, $actual] = $this->build($schema);

        self::assertSame($built, $enum instanceof EnumModel);
        self::assertSame($messages, $actual);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, bool, list<string>}>
     */
    public static function problems(): array
    {
        $at = self::AT;

        return [
            'mixed' => [['enum' => ['a', 1]], false, ["error {$at}/enum: The enum mixes strings and integers, which no PHP enum can back."]],
            'only null' => [['enum' => [null]], false, ["error {$at}/enum: The enum has no value besides null."]],
            'float' => [['enum' => ['a', 1.5]], false, ["error {$at}/enum/1: Enum value 1.5 cannot back a PHP enum; use strings or integers."]],
            'boolean' => [['enum' => [true]], false, ["error {$at}/enum/0: Enum value true cannot back a PHP enum; use strings or integers."]],
            'type mismatch' => [['type' => 'integer', 'enum' => ['a']], false, ["error {$at}/type: \"type\" does not match the enum values, which are strings."]],
            'nullable type' => [['type' => ['string', 'null'], 'enum' => ['a']], true, []],
            'duplicate' => [['enum' => ["a/\u{00FC}", "a/\u{00FC}"]], true, ["warning {$at}/enum/1: Enum value \"a/\u{00FC}\" is listed twice."]],
            'string and integer one' => [['enum' => ['1', 1]], false, ["error {$at}/enum: The enum mixes strings and integers, which no PHP enum can back."]],
            'whole float' => [['enum' => [2.0]], false, ["error {$at}/enum/0: Enum value 2.0 cannot back a PHP enum; use strings or integers."]],
            'integers declared as strings' => [['type' => 'string', 'enum' => [1]], false, ["error {$at}/type: \"type\" does not match the enum values, which are integers."]],
            'empty descriptions' => [['enum' => ['a'], 'x-enum-descriptions' => []], true, []],
            'descriptions as a list' => [['enum' => ['a'], 'x-enum-descriptions' => ['A']], true, ["error {$at}/x-enum-descriptions: \"x-enum-descriptions\" must map enum values to descriptions."]],
            'same case' => [['enum' => ['eur', 'EUR']], false, ["error {$at}/enum/1: Enum values \"eur\" and \"EUR\" both become case EUR."]],
            'no usable characters' => [['enum' => ['***']], false, ["error {$at}/enum/0: Enum value \"***\" has no characters usable in a case name."]],
            'descriptions not a map' => [['enum' => ['a'], 'x-enum-descriptions' => 'A'], true, ["error {$at}/x-enum-descriptions: \"x-enum-descriptions\" must map enum values to descriptions."]],
            'description for no value' => [['enum' => ['a'], 'x-enum-descriptions' => ['b' => 'B']], true, ["error {$at}/x-enum-descriptions/b: There is no enum value \"b\"."]],
            'description not a string' => [['enum' => ['a'], 'x-enum-descriptions' => ['a' => 5]], true, ["error {$at}/x-enum-descriptions/a: An enum description must be a string."]],
        ];
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array{?EnumModel, list<string>}
     */
    private function build(array $schema): array
    {
        $diagnostics = new Diagnostics();
        $enum = (new EnumBuilder(new NameResolver()))->build(ClassName::fromFqcn('App\Dto\E'), GraphFixture::load(['E' => $schema])->all()[0]->schema(), $diagnostics);

        return [$enum, array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())];
    }

    /**
     * @return array<string, int|string>
     */
    private function cases(EnumModel $enum): array
    {
        $cases = [];
        foreach ($enum->cases() as $case) {
            self::assertInstanceOf(EnumCase::class, $case);
            $cases[$case->name()] = $case->value();
        }

        return $cases;
    }

    public function testKeepsGoingAfterNullsAndDuplicates(): void
    {
        [$enum, $messages] = $this->build(['enum' => [null, 'a', 'a', 'b']]);

        self::assertInstanceOf(EnumModel::class, $enum);
        self::assertSame(['A' => 'a', 'B' => 'b'], self::cases($enum));
        self::assertSame(['warning ' . self::AT . '/enum/2: Enum value "a" is listed twice.'], $messages);
    }

    public function testDescribesIntegerCases(): void
    {
        [$enum] = $this->build(['enum' => [1, 2], 'x-enum-descriptions' => ['2' => 'Two.']]);

        self::assertInstanceOf(EnumModel::class, $enum);
        self::assertNull($enum->cases()[0]->doc()->description());
        self::assertSame('Two.', $enum->cases()[1]->doc()->description());
    }
}
