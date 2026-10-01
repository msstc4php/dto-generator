<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Exception;

use Closure;
use MSSTC4PHP\DtoGenerator\Domain\Exception\DomainError;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use PHPUnit\Framework\TestCase;

final class DomainErrorTest extends TestCase
{
    /**
     * @dataProvider failingOperations
     *
     * @param Closure(): void $operation
     */
    public function testOuterLayersCatchEveryDomainFailureInOnePlace(Closure $operation): void
    {
        $this->expectException(DomainError::class);

        $operation();
    }

    /**
     * @return array<string, array{Closure(): void}>
     */
    public static function failingOperations(): array
    {
        return [
            'invalid model' => [static function (): void {
                ClassName::fromFqcn('');
            }],
            'unsupported php version' => [static function (): void {
                PhpVersion::fromString('9.9');
            }],
            'invalid enum value' => [static function (): void {
                MetadataMode::from('attribute');
            }],
            'incompatible target' => [static function (): void {
                new TargetProfile(
                    PhpVersion::fromString('7.4'),
                    MetadataMode::from(MetadataMode::ATTRIBUTES),
                    Mutability::from(Mutability::IMMUTABLE),
                    AccessorStyle::from(AccessorStyle::AUTO),
                    DateTimeClass::from(DateTimeClass::IMMUTABLE),
                    true,
                );
            }],
        ];
    }
}
