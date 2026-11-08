<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\TaxProfileSource;
use App\Models\TaxProfile;

/** Perfil efetivo de um produto e de onde ele veio (spec fase4-fiscal §3.3). Perfil com `cfops`. */
final readonly class ResolvedTaxProfile
{
    public function __construct(public TaxProfile $profile, public TaxProfileSource $source) {}

    /** @return array{id: int, name: string, source: string} */
    public function summary(): array
    {
        return ['id' => $this->profile->id, 'name' => $this->profile->name, 'source' => $this->source->value];
    }
}
