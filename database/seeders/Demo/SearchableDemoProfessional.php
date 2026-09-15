<?php

namespace Database\Seeders\Demo;

use App\Enums\PetSpecies;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\Registration\ProfessionalServiceItem;
use App\Enums\ServiceCategory;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Torna a conta de demonstração `vet@2pets.com.br` (Dra. Carolina Mendes) VISÍVEL na busca
 * pública — e coerente com o que ela diz ser.
 *
 * ── O defeito que isto corrige ────────────────────────────────────────────────────────
 * Ela nascia com `profile_completed = false`, `registration_status = 'completed'` e
 * `location = NULL`. `User::scopeVisibleProfessional()` exige `profile_completed = true` E
 * `registration_status = 'approved'`: ela falhava nos dois, e sem coordenada jamais
 * apareceria numa busca com geo. Resultado medido: `?query=Carolina Mendes` devolvia zero.
 * A conta que o time usa para testar o produto era justamente a única que o produto não
 * mostrava.
 *
 * ⚠️ `'completed'` NÃO é `'approved'`. São dois valores distintos de `registration_status` e
 * a diferença é invisível na leitura do seeder — o cadastro está preenchido, mas ninguém o
 * aprovou, e a busca só enxerga aprovado.
 *
 * ── Por que classe própria, e não mais um bloco no `DemoDataSeeder` ───────────────────
 * O `DemoDataSeeder` já cuida de tutor, pets, vacinas, vermífugo, agendamentos e admin.
 * Visibilidade de busca (flags de cadastro + PostGIS + oferta + serviços) é outra
 * responsabilidade e tem regra própria; embutida lá, o seeder passaria de 260 linhas e a
 * regra ficaria escondida no meio de dado de demonstração.
 */
final class SearchableDemoProfessional
{
    /** Av. Paulista, São Paulo — dentro da região beta (SP + Grande SP + Campinas). */
    private const LATITUDE = -23.5614;

    private const LONGITUDE = -46.6559;

    /**
     * Oferta coerente com o que a descrição dela já dizia ("cardiologia e clínica geral de
     * pequenos animais") e com o que um vet volante pode oferecer segundo
     * `ProfessionalOfferingMatrix` — nada aqui é escolhido fora da matriz.
     *
     * @var list<string>
     */
    private const SPECIALTIES = ['Cardiologia', 'Clinica Geral'];

    /**
     * Cão e gato: "pequenos animais" da própria descrição, e nenhuma especialidade
     * espécie-específica restringindo (ver `Dataset\SpeciesCoverage`).
     *
     * @var list<string>
     */
    private const SPECIES_SERVED = [PetSpecies::DOG->value, PetSpecies::CAT->value];

    public function provision(User $user, Professional $professional): void
    {
        $this->publishUser($user);
        $this->completeOffering($professional);
        $this->syncServices($user);
    }

    /**
     * `location` (geography) só existe por SQL cru — `ST_MakePoint(longitude, latitude)`,
     * com a LONGITUDE primeiro. Fica na mesma transação do UPDATE de latitude/longitude para
     * nunca haver uma janela em que as três colunas discordem.
     */
    private function publishUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'user_type' => 'vet',
                'profile_completed' => true,
                'registration_status' => 'approved',
                'is_suspended' => false,
                'consent_search_visibility' => true,
                'address' => 'Avenida Paulista',
                'number' => '1578',
                'neighborhood' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01310-200',
                'latitude' => self::LATITUDE,
                'longitude' => self::LONGITUDE,
            ])->save();

            DB::statement(
                'UPDATE users SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                [self::LONGITUDE, self::LATITUDE, $user->id],
            );
        });
    }

    private function completeOffering(Professional $professional): void
    {
        $professional->update([
            'specialties' => self::SPECIALTIES,
            'species_served' => self::SPECIES_SERVED,
            'services_offered' => $this->serviceItemValues(),
            'equipment' => [ClinicalEquipment::ECG_MACHINE->value, ClinicalEquipment::PORTABLE_MULTIPARAMETER_MONITOR->value],
            'home_visit_available' => true,
            'mobile_service' => true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function serviceItemValues(): array
    {
        return [
            ProfessionalServiceItem::HEALTH_CERTIFICATE->value,
            ProfessionalServiceItem::DEWORMING->value,
            ProfessionalServiceItem::ECG->value,
        ];
    }

    /**
     * `services.professional_id` referencia `users.id` (não `professionals.id`) — a mesma
     * convenção que `ProfessionalSearchService::applyServiceFilters()` assume.
     */
    private function syncServices(User $user): void
    {
        foreach ($this->serviceRows() as $row) {
            Service::updateOrCreate(
                ['professional_id' => $user->id, 'name' => $row['name']],
                [...$row, 'active' => true],
            );
        }
    }

    /**
     * @return list<array{name: string, category: string, duration: int, price: float}>
     */
    private function serviceRows(): array
    {
        return [
            ['name' => 'Consulta Veterinaria', 'category' => ServiceCategory::CONSULTATION->value, 'duration' => 40, 'price' => 250.00],
            ['name' => 'Eletrocardiograma (ECG)', 'category' => ServiceCategory::IMAGING->value, 'duration' => 30, 'price' => 190.00],
            ['name' => 'Vacinacao', 'category' => ServiceCategory::VACCINATION->value, 'duration' => 15, 'price' => 120.00],
        ];
    }
}
