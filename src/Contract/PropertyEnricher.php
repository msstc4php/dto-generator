<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;

interface PropertyEnricher
{
    /**
     * @return list<AttributeModel> attributes for the property, in output order
     */
    public function enrichProperty(PropertyContext $context): array;
}
