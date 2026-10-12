<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;

/**
 * The generator enriches a class before its properties, so an enricher that is both may carry what it read from the
 * class's schema over to them.
 *
 * @api
 */
interface ClassEnricher
{
    /**
     * @return list<AttributeModel> attributes for the class, in output order
     */
    public function enrichClass(ClassContext $context): array;
}
