<?php

namespace App\Models;

use App\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Model;

/**
 * CSC da NFC-e (Código de Segurança do Contribuinte) por organização e ambiente — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §4. Assina o QR Code do DANFCE.
 * O token só passa por `FiscalNfceCscService` (cifrado); `$hidden` impede vazamento por JSON.
 */
class FiscalNfceCsc extends Model
{
    protected $fillable = ['organization_id', 'environment', 'csc_id', 'updated_by'];

    protected $hidden = ['token_ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['environment' => FiscalEnvironment::class];
    }
}
