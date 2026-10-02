<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

interface CodeEmitter
{
    /**
     * The complete source of the PHP file declaring the class, ending with a newline.
     *
     * @param list<PropertyModel> $inherited the properties of its ancestors, root first: its constructor takes them too
     */
    public function emit(ClassModel $class, TargetProfile $target, array $inherited = []): string;

    /**
     * The complete source of the PHP file declaring the enum: a native enum from PHP 8.1, a class of constants before.
     */
    public function emitEnum(EnumModel $enum, TargetProfile $target): string;
}
