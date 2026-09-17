<?php

namespace Tests\Unit;

use App\Enums\ServiceCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §2 —
 * classificação de grupo por categoria, a base pura (sem banco) de
 * `MedicalRecordEncounterResolver::resolveKind()`.
 */
class ServiceCategoryEncounterClassificationTest extends TestCase
{
    #[DataProvider('examCategoryProvider')]
    public function test_is_exam_is_true_only_for_laboratory_and_imaging(ServiceCategory $category, bool $expected): void
    {
        $this->assertSame($expected, $category->isExam());
    }

    /** @return array<string, array{ServiceCategory, bool}> */
    public static function examCategoryProvider(): array
    {
        return [
            'laboratory' => [ServiceCategory::LABORATORY, true],
            'imaging' => [ServiceCategory::IMAGING, true],
            'consultation' => [ServiceCategory::CONSULTATION, false],
            'dental' => [ServiceCategory::DENTAL, false],
            'grooming' => [ServiceCategory::GROOMING, false],
        ];
    }

    #[DataProvider('veterinarianGatedProvider')]
    public function test_is_veterinarian_gated_is_true_only_for_nutrition_and_behavioral(ServiceCategory $category, bool $expected): void
    {
        $this->assertSame($expected, $category->isVeterinarianGated());
    }

    /** @return array<string, array{ServiceCategory, bool}> */
    public static function veterinarianGatedProvider(): array
    {
        return [
            'nutrition' => [ServiceCategory::NUTRITION, true],
            'behavioral' => [ServiceCategory::BEHAVIORAL, true],
            'consultation' => [ServiceCategory::CONSULTATION, false],
            'training' => [ServiceCategory::TRAINING, false],
        ];
    }

    #[DataProvider('nonClinicalProvider')]
    public function test_is_non_clinical_matches_group_d_including_hospitalization(ServiceCategory $category, bool $expected): void
    {
        $this->assertSame($expected, $category->isNonClinical());
    }

    /** @return array<string, array{ServiceCategory, bool}> */
    public static function nonClinicalProvider(): array
    {
        return [
            'grooming' => [ServiceCategory::GROOMING, true],
            'training' => [ServiceCategory::TRAINING, true],
            'boarding' => [ServiceCategory::BOARDING, true],
            'other' => [ServiceCategory::OTHER, true],
            // Fora de escopo desta correção (§4) — não pode cair no ramo clínico genérico,
            // que seria inventar um substituto malfeito do módulo de internação de verdade.
            'hospitalization' => [ServiceCategory::HOSPITALIZATION, true],
            'consultation' => [ServiceCategory::CONSULTATION, false],
            'laboratory' => [ServiceCategory::LABORATORY, false],
            'nutrition' => [ServiceCategory::NUTRITION, false],
        ];
    }

    /**
     * §4: `boarding`/`hospitalization` são reserva por diária, não por slot de horário —
     * o tutor não pode autoagendar nenhuma das duas pelo seletor de horário
     * (`BookingService::assertServiceIsTutorBookable`).
     */
    #[DataProvider('selfBookableByTutorProvider')]
    public function test_is_self_bookable_by_tutor_is_false_only_for_boarding_and_hospitalization(ServiceCategory $category, bool $expected): void
    {
        $this->assertSame($expected, $category->isSelfBookableByTutor());
    }

    /** @return array<string, array{ServiceCategory, bool}> */
    public static function selfBookableByTutorProvider(): array
    {
        return [
            'boarding' => [ServiceCategory::BOARDING, false],
            'hospitalization' => [ServiceCategory::HOSPITALIZATION, false],
            'consultation' => [ServiceCategory::CONSULTATION, true],
            'grooming' => [ServiceCategory::GROOMING, true],
            'imaging' => [ServiceCategory::IMAGING, true],
        ];
    }

    /**
     * Nenhuma categoria pode cair em mais de um grupo ao mesmo tempo — os três métodos
     * particionam o enum inteiro, e `MedicalRecordEncounterResolver::resolveKind()` confia
     * nisso para nunca avaliar dois `if` verdadeiros para a mesma categoria.
     */
    public function test_the_three_groups_never_overlap_for_any_category(): void
    {
        foreach (ServiceCategory::cases() as $category) {
            $memberships = array_filter([
                $category->isExam(),
                $category->isVeterinarianGated(),
                $category->isNonClinical(),
            ]);

            $this->assertLessThanOrEqual(1, count($memberships), "Categoria {$category->value} pertence a mais de um grupo.");
        }
    }
}
