<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * @phpstan-import-type JsonValue from Json
 * @phpstan-import-type AttributeDeclaration from AttributeModel
 */
final class ExtensionSettings
{
    /** @var list<ClassName> */
    private array $classes;

    private bool $discover;

    /** @var array<int|string, JsonValue> */
    private array $config;

    /** @var array<string, AttributeDeclaration> */
    private array $aliases;

    private ?bool $verifyClasses;

    /**
     * @param list<ClassName> $classes extensions listed explicitly, in order
     * @param array<int|string, JsonValue> $config extensionConfig sections by extension name
     * @param array<string, AttributeDeclaration> $aliases attributeAliases: key → attribute template
     * @param bool|null $verifyClasses null for "auto"
     */
    public function __construct(array $classes, bool $discover, array $config, array $aliases, ?bool $verifyClasses)
    {
        $this->classes = $classes;
        $this->discover = $discover;
        $this->config = $config;
        $this->aliases = $aliases;
        $this->verifyClasses = $verifyClasses;
    }

    /**
     * @return list<ClassName>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function discover(): bool
    {
        return $this->discover;
    }

    /**
     * @return array<int|string, JsonValue>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, AttributeDeclaration>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function verifyClasses(): ?bool
    {
        return $this->verifyClasses;
    }
}
