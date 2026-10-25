<?php

namespace App\Services\Wallet;

use App\Models\Pet;

/** O mínimo de um pet que a Carteira mostra: nome e foto, para o tutor reconhecer de relance. */
final class WalletPetSummary
{
    /** @return array{id: int, name: string, image: ?string} */
    public static function from(Pet $pet): array
    {
        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'image' => $pet->image_url,
        ];
    }
}
