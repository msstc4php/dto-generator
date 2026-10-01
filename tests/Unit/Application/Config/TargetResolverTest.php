<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use PHPUnit\Framework\TestCase;

final class TargetResolverTest extends TestCase
{
    public function testUsesAnExplicitVersion(): void
    {
        $profile = $this->resolve($this->config('8.3'), '^7.4');

        self::assertNotNull($profile);
        self::assertSame('8.3', $profile->php()->toString());
        self::assertTrue($profile->metadata()->isAttributes());
    }

    /**
     * @dataProvider detected
     */
    public function testDetectsTheVersionFromComposer(?string $constraint, string $expected, string $metadata): void
    {
        $profile = $this->resolve($this->config(null), $constraint);

        self::assertNotNull($profile);
        self::assertSame($expected, $profile->php()->toString());
        self::assertSame($metadata, $profile->metadata()->value());
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function detected(): array
    {
        return [
            'caret 8.1' => ['^8.1', '8.1', 'attributes'],
            'no composer.json' => [null, '7.4', 'annotations'],
            'wildcard' => ['*', '7.4', 'annotations'],
        ];
    }

    /**
     * @dataProvider clamped
     */
    public function testClampsUnsupportedDetectedVersionsWithAWarning(string $constraint, string $expected): void
    {
        $diagnostics = new Diagnostics();
        $profile = (new TargetResolver(new FixedPhpConstraint($constraint)))->resolve($this->config(null), $diagnostics);

        self::assertNotNull($profile);
        self::assertSame($expected, $profile->php()->toString());
        self::assertFalse($diagnostics->hasErrors());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString(sprintf('generating for PHP %s instead', $expected), $diagnostics->all()[0]->message());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function clamped(): array
    {
        return [
            'too old' => ['^7.2', '7.4'],
            'too new' => ['^9.0', '8.5'],
        ];
    }

    public function testReportsAnIncompatibleTargetAsAConfigError(): void
    {
        $diagnostics = new Diagnostics();
        $config = $this->config('7.4', MetadataMode::ATTRIBUTES);

        self::assertNull((new TargetResolver(new FixedPhpConstraint(null)))->resolve($config, $diagnostics));
        self::assertSame(
            ['error /project/dto-generator.yaml#/target: Metadata mode "attributes" requires attributes (PHP 8.0+), but the target is PHP 7.4.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testRejectsReservedNamespaceSegmentsBeforePhp80(): void
    {
        $diagnostics = new Diagnostics();
        $config = $this->config('7.4', null, 'App\List\Dto', ['uuid' => ClassName::fromFqcn('Vendor\Fn\Uuid')]);

        self::assertNull((new TargetResolver(new FixedPhpConstraint(null)))->resolve($config, $diagnostics));
        self::assertSame(
            [
                'error /project/dto-generator.yaml#/sources/0/namespace: Namespace "App\List\Dto" contains the reserved word "List", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0).',
                'error /project/dto-generator.yaml#/formats/uuid/type: Namespace "Vendor\Fn" contains the reserved word "Fn", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0).',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testAllowsReservedNamespaceSegmentsFromPhp80(): void
    {
        self::assertNotNull($this->resolve($this->config('8.0', null, 'App\List\Dto'), null));
    }

    private function resolve(GeneratorConfig $config, ?string $constraint): ?TargetProfile
    {
        $diagnostics = new Diagnostics();
        $profile = (new TargetResolver(new FixedPhpConstraint($constraint)))->resolve($config, $diagnostics);
        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));

        return $profile;
    }

    /**
     * @param array<int|string, ClassName> $formats
     */
    private function config(?string $php, ?string $metadata = null, string $namespace = 'App\Dto', array $formats = []): GeneratorConfig
    {
        return new GeneratorConfig(
            ConfigMother::PATH,
            new TargetSettings($php === null ? null : PhpVersion::fromString($php), $metadata === null ? null : MetadataMode::from($metadata), true),
            new DtoSettings(
                Mutability::from(Mutability::IMMUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                AllOfStrategy::from(AllOfStrategy::EXTENDS),
            ),
            $formats,
            new ExtensionSettings([], true, [], [], null),
            [ConfigMother::source('/project/api/openapi.yaml', ['*'], [], $namespace)],
        );
    }

    public function testReportsANumericFormatNameWithItsLocation(): void
    {
        $diagnostics = new Diagnostics();
        $config = $this->config('7.4', null, 'App\\Dto', ['200' => ClassName::fromFqcn('Vendor\\List\\Ok')]);

        self::assertNull((new TargetResolver(new FixedPhpConstraint(null)))->resolve($config, $diagnostics));
        self::assertNotNull($diagnostics->errors()[0]->location());
        self::assertSame('/formats/200/type', $diagnostics->errors()[0]->location()->pointer());
    }
}
