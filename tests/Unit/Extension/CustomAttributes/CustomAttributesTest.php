<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Extension\CustomAttributes;

use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Extension\CustomAttributes\CustomAttributes;
use MSSTC4PHP\DtoGenerator\Tests\Support\AttributeFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class CustomAttributesTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/Tag/properties/label';

    /**
     * @dataProvider attributes
     *
     * @param list<mixed> $declared
     * @param list<string> $expected
     */
    public function testTranslatesTheAttributeGrammar(array $declared, array $expected): void
    {
        [$attributes, $messages] = $this->property(['type' => 'string', 'x-php-attributes' => $declared]);

        self::assertSame([], $messages);
        self::assertSame($expected, $attributes);
    }

    /**
     * @return array<string, array{list<mixed>, list<string>}>
     */
    public static function attributes(): array
    {
        return [
            'class alone' => [[['class' => 'App\Attr\Sensitive']], ['App\Attr\Sensitive()']],
            'leading backslash' => [[['class' => '\App\Attr\Sensitive']], ['App\Attr\Sensitive()']],
            'named arguments' => [
                [['class' => 'App\Attr\Mask', 'args' => ['keep' => 4, 'mode' => ['const' => 'App\Mask::TAIL'], 'target' => ['class' => 'App\Model\User']]]],
                ['App\Attr\Mask(keep: 4, mode: App\Mask::TAIL, target: App\Model\User::class)'],
            ],
            'positional arguments' => [[['class' => 'App\Attr\Range', 'args' => [1, 'x', null, true, 1.5]]], ["App\\Attr\\Range(1, 'x', NULL, true, 1.5)"]],
            'nested new' => [
                [['class' => 'App\Attr\Mask', 'args' => ['inner' => ['new' => ['class' => 'App\Attr\Rule', 'args' => [1]]]]]],
                ['App\Attr\Mask(inner: new App\Attr\Rule(1))'],
            ],
            'new without arguments' => [[['class' => 'App\A', 'args' => [['new' => ['class' => 'App\B']]]]], ['App\A(new App\B())']],
            'list and map values' => [
                [['class' => 'App\Attr\Groups', 'args' => [['a', 'b'], ['x' => 1, 'y' => ['z']]]]],
                ["App\\Attr\\Groups(['a', 'b'], ['x' => 1, 'y' => ['z']])"],
            ],
            'global constant' => [[['class' => 'App\A', 'args' => [['const' => 'PHP_INT_MAX']]]], ['App\A(PHP_INT_MAX)']],
            'literal map with a marker key' => [
                [['class' => 'App\A', 'args' => [['literal' => ['class' => 'not a reference']]]]],
                ["App\\A(['class' => 'not a reference'])"],
            ],
            'empty args' => [[['class' => 'App\A', 'args' => []]], ['App\A()']],
            'several attributes in order' => [[['class' => 'App\A'], ['class' => 'App\B']], ['App\A()', 'App\B()']],
            'map with more keys than a marker' => [[['class' => 'App\A', 'args' => [['class' => 'x', 'other' => 1]]]], ["App\\A(['class' => 'x', 'other' => 1])"]],
        ];
    }

    /**
     * @dataProvider problems
     *
     * @param mixed $declared
     * @param list<string> $expected
     */
    public function testReportsMistakesAndSkipsTheBrokenAttribute($declared, array $expected): void
    {
        [$attributes, $messages] = $this->property(['type' => 'string', 'x-php-attributes' => $declared]);

        self::assertSame($expected, $messages);
        self::assertNotContains('App\Broken()', $attributes);
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function problems(): array
    {
        $at = self::AT . '/x-php-attributes';

        return [
            'not a list' => [['class' => 'App\Broken'], ["error {$at}: \"x-php-attributes\" must be a list of attributes."]],
            'scalar' => ['App\Broken', ["error {$at}: \"x-php-attributes\" must be a list of attributes."]],
            'entry not an object' => [['App\Broken'], ["error {$at}/0: An attribute must be an object with \"class\" and optional \"args\"."]],
            'missing class' => [[['args' => [1]]], ["error {$at}/0: An attribute must be an object with \"class\" and optional \"args\"."]],
            'class not a string' => [[['class' => 5]], ["error {$at}/0/class: \"class\" must be a class name."]],
            'invalid class' => [[['class' => 'Not A Class']], ["error {$at}/0/class: \"Not A Class\" is not a valid class name: segment \"Not A Class\" is not a PHP identifier."]],
            'unknown key' => [[['class' => 'App\Broken', 'arguments' => []]], ["error {$at}/0/arguments: Unknown key \"arguments\"; an attribute takes \"class\" and \"args\"."]],
            'args not a container' => [[['class' => 'App\Broken', 'args' => 'x']], ["error {$at}/0/args: \"args\" must be a list or an object."]],
            'invalid argument name' => [[['class' => 'App\Broken', 'args' => ['not valid' => 1]]], ["error {$at}/0/args/not valid: Argument name \"not valid\" is not a PHP identifier."]],
            'constant not a string' => [[['class' => 'App\Broken', 'args' => [['const' => 1]]]], ["error {$at}/0/args/0/const: \"const\" must be a constant name like \"App\\Mask::TAIL\"."]],
            'class constant of ::class' => [[['class' => 'App\Broken', 'args' => [['const' => 'App\A::class']]]], ["error {$at}/0/args/0/const: \"class\" is not a valid constant name; use classReference() for ::class."]],
            'class reference not a string' => [[['class' => 'App\Broken', 'args' => [['class' => []]]]], ["error {$at}/0/args/0/class: \"class\" must be a class name."]],
            'new without class' => [[['class' => 'App\Broken', 'args' => [['new' => ['args' => [1]]]]]], ["error {$at}/0/args/0/new: \"new\" must be an object with \"class\" and optional \"args\"."]],
            'literal not a map' => [[['class' => 'App\Broken', 'args' => [['literal' => 5]]]], ["error {$at}/0/args/0/literal: \"literal\" must be an object."]],
            'one broken among good' => [[['class' => 'App\Good'], ['class' => 5]], ["error {$at}/1/class: \"class\" must be a class name."]],
        ];
    }

    public function testExpandsAliases(): void
    {
        $aliases = [
            'x-audit' => ['class' => 'App\Attr\Audited', 'args' => ['level' => '{value}']],
            'x-owner' => ['class' => 'App\Attr\Owner', 'args' => ['by' => '{value.user}', 'note' => 'set by {value.user} at {value.level}', 'all' => '{value}']],
            'x-plain' => ['class' => 'App\Attr\Plain'],
        ];
        [$attributes, $messages] = $this->property(
            ['type' => 'string', 'x-plain' => true, 'x-owner' => ['user' => 'ann', 'level' => 3], 'x-audit' => 2, 'x-php-attributes' => [['class' => 'App\Attr\First']]],
            $aliases,
        );

        self::assertSame([], $messages);
        self::assertSame(
            [
                'App\Attr\First()',
                'App\Attr\Audited(level: 2)',
                "App\\Attr\\Owner(by: 'ann', note: 'set by ann at 3', all: ['user' => 'ann', 'level' => 3])",
                'App\Attr\Plain()',
            ],
            $attributes,
        );
    }

    /**
     * @dataProvider aliasProblems
     *
     * @param array<array-key, mixed> $alias
     * @param mixed $value
     */
    public function testReportsAliasesThatCannotExpand(array $alias, $value, string $expected): void
    {
        [$attributes, $messages] = $this->property(['type' => 'string', 'x-audit' => $value], ['x-audit' => $alias]);

        self::assertSame([], $attributes);
        self::assertSame([$expected], $messages);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, mixed, string}>
     */
    public static function aliasProblems(): array
    {
        $at = self::AT . '/x-audit';

        return [
            'missing key' => [['class' => 'App\A', 'args' => ['by' => '{value.user}']], ['level' => 1], "error {$at}: Alias \"x-audit\" needs \"user\" in its value."],
            'key of a scalar' => [['class' => 'App\A', 'args' => ['by' => '{value.user}']], 'ann', "error {$at}: Alias \"x-audit\" needs \"user\" in its value."],
            'object inside text' => [['class' => 'App\A', 'args' => ['note' => 'by {value}']], ['user' => 'ann'], "error {$at}: Alias \"x-audit\" puts {value} inside text, so the value must be a string, a number or a boolean."],
            'invalid class' => [['class' => 'Not A Class'], true, "error {$at}: Alias \"x-audit\": \"Not A Class\" is not a valid class name: segment \"Not A Class\" is not a PHP identifier."],
        ];
    }

    public function testReadsTheAttributesOfAClass(): void
    {
        $graph = GraphFixture::load(['Tag' => [
            'type' => 'object',
            'x-php-attributes' => [['class' => 'App\Attr\Entity']],
            'x-audit' => 'high',
            'properties' => ['label' => ['type' => 'string']],
        ]]);
        $diagnostics = new Diagnostics();
        $extension = $this->extension(['x-audit' => ['class' => 'App\Attr\Audited', 'args' => ['{value}']]]);
        $context = new ClassContext(EmitterFixture::tag(), $graph->all()[0]->schema(), EmitterFixture::target('8.2', Mutability::IMMUTABLE), new InstalledPackages(), $diagnostics);

        self::assertSame(['App\\Attr\\Entity()', "App\\Attr\\Audited('high')"], $this->describe($extension->enrichClass($context)));
        self::assertSame([], $diagnostics->all());
    }

    public function testRegistersItselfForClassesAndProperties(): void
    {
        $diagnostics = new Diagnostics();
        $registry = new Registry($diagnostics, new SchemaLocation('/project/dto-generator.yaml'));
        $extension = $this->extension([]);
        $registry->register($extension, []);
        $graph = GraphFixture::load(['Tag' => ['type' => 'object', 'x-php-attributes' => [['class' => 'App\C']], 'properties' => ['label' => ['type' => 'string', 'x-php-attributes' => [['class' => 'App\P']]]]]]);
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);
        $tag = EmitterFixture::tag();

        self::assertSame('custom-attributes', $extension->name());
        self::assertSame(['App\C()'], $this->describe($registry->enrichClass(new ClassContext($tag, $graph->all()[0]->schema(), $target, new InstalledPackages(), $diagnostics))));
        self::assertSame(['App\P()'], $this->describe($registry->enrichProperty(new PropertyContext($tag->properties()[0], $tag, $graph->all()[0]->schema()->requireProperty('label'), $target, new InstalledPackages(), $diagnostics))));
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param array<string, array<array-key, mixed>> $aliases
     *
     * @return array{list<string>, list<string>}
     */
    private function property(array $schema, array $aliases = []): array
    {
        $graph = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => $schema]]]);
        $diagnostics = new Diagnostics();
        $tag = EmitterFixture::tag();
        $context = new PropertyContext(
            $tag->properties()[0],
            $tag,
            $graph->all()[0]->schema()->requireProperty('label'),
            EmitterFixture::target('8.2', Mutability::IMMUTABLE),
            new InstalledPackages(),
            $diagnostics,
        );

        return [
            $this->describe($this->extension($aliases)->enrichProperty($context)),
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all()),
        ];
    }

    /**
     * @param array<string, array<array-key, mixed>> $aliases
     */
    private function extension(array $aliases): CustomAttributes
    {
        return new CustomAttributes($aliases);
    }

    /**
     * @param list<AttributeModel> $attributes
     *
     * @return list<string>
     */
    private function describe(array $attributes): array
    {
        return array_map([AttributeFixture::class, 'describe'], $attributes);
    }
}
