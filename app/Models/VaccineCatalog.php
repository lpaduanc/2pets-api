<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated Substituído por `ImmunizationProduct` (`group = vaccine`) — contrato
 * `docs/gap-simplesvet/contratos/13-contrato-api.md` e spec
 * `docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md`. `MasterDataController` e
 * `VaccinationImportValidator` já leem `ImmunizationProduct`; esta classe/tabela ficam só
 * como histórico (11 registros globais seedados, migrados 1:1 por
 * `database/migrations/2026_10_06_100006_seed_immunization_products_from_vaccine_catalog.php`).
 *
 * Plano de remoção: não dropar `vaccine_catalog` enquanto nenhuma auditoria confirmar que
 * nada externo (import antigo, relatório, dump) referencia esta tabela por nome. Quando
 * isso for confirmado, remover nesta ordem: (1) `VaccineCatalogSeeder`/
 * `ImmunizationProductSeeder` (parar de escrever/copiar), (2) esta classe, (3) migration de
 * `DROP TABLE vaccine_catalog` com guard `DB::getDriverName() !== 'pgsql'` (ver
 * "Armadilhas conhecidas" do backend-specialist). Não remover antes de decisão explícita do
 * usuário — só leitura histórica até lá.
 */
class VaccineCatalog extends Model
{
    use HasFactory;

    protected $table = 'vaccine_catalog';

    protected $fillable = [
        'name',
        'species',
        'doses_required',
        'interval_days',
        'booster_interval_days',
        'description',
        'required',
    ];

    protected function casts(): array
    {
        return [
            'doses_required' => 'integer',
            'interval_days' => 'integer',
            'booster_interval_days' => 'integer',
            'required' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeBySpecies($query, string $species)
    {
        return $query->where('species', $species);
    }

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }
}
