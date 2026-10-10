<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;

/**
 * What the read and write views of a direction-dependent class add to its name (spec F1 §2).
 */
final class ViewSuffixes
{
    public const READ = 'Read';

    public const WRITE = 'Write';

    private string $read;

    private string $write;

    /**
     * @throws InvalidArgumentException unless both are PHP identifiers that differ, letter case ignored
     */
    public function __construct(string $read = self::READ, string $write = self::WRITE)
    {
        if (!Identifier::isValid($read) || !Identifier::isValid($write) || Identifier::asciiLower($read) === Identifier::asciiLower($write)) {
            throw new InvalidArgumentException(sprintf('View suffixes must be distinct PHP identifiers, got "%s" and "%s".', $read, $write));
        }

        $this->read = $read;
        $this->write = $write;
    }

    public function read(): string
    {
        return $this->read;
    }

    public function write(): string
    {
        return $this->write;
    }

    public function of(Direction $direction): string
    {
        return $direction->value() === Direction::READ ? $this->read : $this->write;
    }
}
