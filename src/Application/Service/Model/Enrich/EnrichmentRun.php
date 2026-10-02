<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaIndex;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * What one Enrich run shares between its classes: the input, the schema index, the diagnostics and verifyClasses.
 */
final class EnrichmentRun
{
    private Input $input;

    private SchemaIndex $index;

    private Diagnostics $diagnostics;

    private ?AttributeVerification $verification = null;

    private SchemaReferences $references;

    public function __construct(Input $input, Diagnostics $diagnostics)
    {
        $this->input = $input;
        $this->index = SchemaIndex::of($input->graph());
        $this->references = new SchemaReferences($input->graph());
        $this->diagnostics = $diagnostics;
        $verifier = $input->verifier();
        if ($verifier instanceof ClassVerifier && !$input->target()->metadata()->isNone()) {
            $this->verification = new AttributeVerification($verifier, $input->classes(), $input->enums(), $diagnostics);
        }
    }

    public function input(): Input
    {
        return $this->input;
    }

    public function index(): SchemaIndex
    {
        return $this->index;
    }

    public function references(): SchemaReferences
    {
        return $this->references;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /**
     * @param list<AttributeModel> $attributes
     */
    public function verify(array $attributes, SchemaLocation $at): void
    {
        if ($this->verification instanceof AttributeVerification) {
            $this->verification->verify($attributes, $at);
        }
    }
}
