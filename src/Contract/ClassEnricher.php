<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;

/**
 * @api
 */
interface ClassEnricher
{
    /**
     * @return list<AttributeModel> attributes for the class, in output order
     */
    public function enrichClass(ClassContext $context): array;
}
