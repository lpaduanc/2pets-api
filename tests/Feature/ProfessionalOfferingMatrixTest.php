<?php

namespace Tests\Feature;

use App\Enums\ProfessionalType;
use App\Enums\Registration\ClinicalEquipment;
use App\Enums\ServiceCategory;
use App\Support\Catalog\ProfessionalOfferingMatrix;
use App\Support\Catalog\VeterinarySpecialtyCatalog;
use Tests\TestCase;

/**
 * A matriz `tipo × serviço × especialidade × equipamento` é a fonte da verdade do dataset de
 * desenvolvimento E do teste de relevância ("busca por X só retorna quem realmente oferece
 * X"). Se ela regredir, o dado gerado volta a ser incoerente e a avaliação de busca perde o
 * chão — por isso as regras de domínio que ela encerra estão travadas aqui, uma a uma.
 *
 * Não toca o banco de propósito: é matriz de código.
 */
class ProfessionalOfferingMatrixTest extends TestCase
{
    /**
     * Restrição do CFMV, não preferência estética: petshop sem responsável técnico
     * veterinário não pode oferecer ato médico veterinário
     * (`.claude/agents/pet-business-specialist.md`, "Cadastro de profissionais" item 3).
     *
     * @dataProvider forbiddenMedicalCategoryProvider
     */
    public function test_petshop_cannot_offer_medical_categories(ServiceCategory $category): void
    {
        $this->assertFalse(
            ProfessionalOfferingMatrix::allowsServiceCategory(ProfessionalType::PETSHOP, $category),
            "Petshop não pode oferecer {$category->value} sem responsável técnico veterinário.",
        );
    }

    /**
     * @return array<string, array{0: ServiceCategory}>
     */
    public static function forbiddenMedicalCategoryProvider(): array
    {
        return [
            'consulta' => [ServiceCategory::CONSULTATION],
            'emergência' => [ServiceCategory::EMERGENCY],
            'cirurgia' => [ServiceCategory::SURGERY],
        ];
    }

    /**
     * Veterinário volante percorre até o cliente com equipamento portátil; não carrega centro
     * cirúrgico nem leito de internação. `imaging`/`laboratory` são permitidos (equipamento
     * portátil é prática de mercado real), `surgery`/`hospitalization` não.
     */
    public function test_mobile_vet_offers_portable_procedures_but_not_structure_dependent_ones(): void
    {
        $allowed = ProfessionalOfferingMatrix::serviceCategoriesFor(ProfessionalType::VET);

        $this->assertContains(ServiceCategory::IMAGING, $allowed);
        $this->assertContains(ServiceCategory::LABORATORY, $allowed);
        $this->assertNotContains(ServiceCategory::SURGERY, $allowed);
        $this->assertNotContains(ServiceCategory::HOSPITALIZATION, $allowed);
        $this->assertNotContains(ServiceCategory::GROOMING, $allowed, 'Veterinário volante não faz banho e tosa.');
    }

    /**
     * Quem não tem a estrutura não pode declarar o equipamento dela. É o que impede o dataset
     * de gerar um banho e tosa com raio-X — cadastro absurdo que envenena a avaliação de
     * relevância.
     *
     * @dataProvider nonClinicalTypeProvider
     */
    public function test_non_clinical_types_have_no_clinical_equipment(ProfessionalType $type): void
    {
        $this->assertSame([], ProfessionalOfferingMatrix::equipmentFor($type));
        $this->assertSame([], ProfessionalOfferingMatrix::specialtiesFor($type));
    }

    /**
     * @return array<string, array{0: ProfessionalType}>
     */
    public static function nonClinicalTypeProvider(): array
    {
        return [
            'petshop' => [ProfessionalType::PETSHOP],
            'banho e tosa' => [ProfessionalType::GROOMING],
            'hotel' => [ProfessionalType::PET_HOTEL],
            'adestramento' => [ProfessionalType::TRAINING],
        ];
    }

    /**
     * Laboratório é diagnóstico, não atendimento clínico: só as duas especialidades cujo
     * `gate` ele pode prestar.
     */
    public function test_laboratory_specialties_are_limited_to_diagnostics(): void
    {
        $this->assertSame(
            ['Diagnostico por Imagem', 'Patologia Clinica'],
            ProfessionalOfferingMatrix::specialtiesFor(ProfessionalType::LABORATORY),
        );
    }

    /**
     * Especialidade cirúrgica exige poder oferecer cirurgia. O vet volante não pode — e a
     * regra é DERIVADA da matriz de capacidades, não escrita à mão, então continua valendo se
     * as capacidades do tipo mudarem.
     */
    public function test_surgical_specialties_require_the_surgery_capability(): void
    {
        $mobileVet = ProfessionalOfferingMatrix::specialtiesFor(ProfessionalType::VET);
        $clinic = ProfessionalOfferingMatrix::specialtiesFor(ProfessionalType::CLINIC);

        $this->assertNotContains('Cirurgia Geral', $mobileVet);
        $this->assertNotContains('Anestesiologia', $mobileVet);
        $this->assertContains('Cirurgia Geral', $clinic);
        $this->assertContains('Clinica Geral', $mobileVet);
    }

    /**
     * "Clinica Geral" era o rótulo MAIS gravado em `professionals.specialties` e não existia
     * no catálogo. A lacuna foi fechada adicionando a linha — não reescrevendo as 45 mil
     * linhas que já a usavam.
     */
    public function test_the_catalog_includes_general_practice(): void
    {
        $names = array_column(VeterinarySpecialtyCatalog::all(), 'name');

        $this->assertContains('Clinica Geral', $names);
        $this->assertSame($names, array_unique($names), 'Nome de especialidade não pode repetir no catálogo.');
    }

    /**
     * Quem oferece imagem tem aparelho de imagem. A dependência já é validada no cadastro
     * (`ServiceEquipmentDependency`); aqui ela é garantida na GERAÇÃO, e limitada ao que o
     * tipo pode ter.
     */
    public function test_imaging_requires_equipment_the_type_can_actually_own(): void
    {
        $required = ProfessionalOfferingMatrix::equipmentRequiredBy(ProfessionalType::VET, ServiceCategory::IMAGING);

        $this->assertNotSame([], $required);
        $this->assertContains(ClinicalEquipment::XRAY_MACHINE, $required);

        $this->assertSame(
            [],
            ProfessionalOfferingMatrix::equipmentRequiredBy(ProfessionalType::GROOMING, ServiceCategory::IMAGING),
            'Banho e tosa não pode oferecer imagem, logo não há equipamento exigível.',
        );
    }

    /**
     * Todo item granular do catálogo pertence à categoria que diz pertencer — é dele que sai
     * o NOME do serviço gerado, e um item na categoria errada produziria exatamente o tipo de
     * incoerência que a matriz existe para impedir.
     */
    public function test_service_items_belong_to_their_own_category(): void
    {
        foreach (ProfessionalOfferingMatrix::serviceItemsFor(ServiceCategory::GROOMING) as $item) {
            $this->assertSame(ServiceCategory::GROOMING, $item->category());
        }

        $this->assertNotSame([], ProfessionalOfferingMatrix::serviceItemsFor(ServiceCategory::GROOMING));
    }

    /**
     * O CHECK do banco, o enum e a lista oferecida pela busca pública precisam concordar. A
     * divergência anterior (5 valores no banco contra 15 no enum) fazia
     * `?service_category=vaccination` devolver vazio para sempre.
     */
    public function test_every_category_in_the_matrix_exists_in_the_enum(): void
    {
        foreach (ProfessionalType::cases() as $type) {
            foreach (ProfessionalOfferingMatrix::serviceCategoriesFor($type) as $category) {
                $this->assertContains($category->value, ServiceCategory::values());
            }
        }
    }
}
