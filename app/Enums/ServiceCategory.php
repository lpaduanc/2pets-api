<?php

namespace App\Enums;

enum ServiceCategory: string
{
    case CONSULTATION = 'consultation';
    case EMERGENCY = 'emergency';
    case SURGERY = 'surgery';
    case VACCINATION = 'vaccination';
    case GROOMING = 'grooming';
    case TRAINING = 'training';
    case BOARDING = 'boarding';
    case LABORATORY = 'laboratory';
    case IMAGING = 'imaging';
    case DENTAL = 'dental';
    case NUTRITION = 'nutrition';
    case BEHAVIORAL = 'behavioral';
    case HOSPITALIZATION = 'hospitalization';
    case REHABILITATION = 'rehabilitation';
    case OTHER = 'other';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`service_category.*`) — pt-BR é o
     * default de `App::getLocale()`; só a rota do schema de cadastro troca o locale por
     * request (ver `App\Http\Middleware\SetLocaleFromAcceptLanguage`). Consumidores fora do
     * schema (ex.: mensagem de dupla trava em `ServiceEquipmentDependency`, busca pública)
     * continuam recebendo pt-BR de propósito — ver relato da tarefa de i18n do cadastro.
     */
    public function label(): string
    {
        return __('registration.service_category.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §2
     * Grupo C: gera prontuário (`Exam`), mas nunca `MedicalRecord` — o laudo é outro relógio,
     * não trava o fechamento do agendamento.
     */
    public function isExam(): bool
    {
        return match ($this) {
            self::LABORATORY, self::IMAGING => true,
            default => false,
        };
    }

    /**
     * Grupo A/B "condicional": nutrição/comportamental só são ato clínico exclusivo quando
     * executados por veterinário (Res. CFMV 1.573/2023 x prática de mercado não regulada
     * para petshop/adestrador) — quem decide o ramo é `MedicalRecordEncounterResolver`, que
     * também sabe QUEM está atendendo.
     */
    public function isVeterinarianGated(): bool
    {
        return match ($this) {
            self::NUTRITION, self::BEHAVIORAL => true,
            default => false,
        };
    }

    /**
     * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §4:
     * `boarding`/`hospitalization` são reserva por diária (par de datas), não por slot de
     * horário — o widget de "escolha um horário" mentiria sobre o compromisso real. O tutor
     * só pode "solicitar"; quem confirma e cria o registro é sempre o profissional
     * (`BookingSource::PROFESSIONAL`). `HOSPITALIZATION` também carrega a regra do dono do
     * produto: internação é sempre iniciada pelo vet, nunca autoagendada pelo tutor.
     */
    public function isSelfBookableByTutor(): bool
    {
        return match ($this) {
            self::BOARDING, self::HOSPITALIZATION => false,
            default => true,
        };
    }

    /**
     * Grupo D: nenhum artefato clínico nasce. `HOSPITALIZATION` entra aqui de propósito —
     * o módulo de internação de verdade é V2 (§4 do contrato) e `MedicalRecordFinalizationService`
     * genérico não pode virar um substituto malfeito dele.
     */
    public function isNonClinical(): bool
    {
        return match ($this) {
            self::GROOMING, self::TRAINING, self::BOARDING, self::OTHER, self::HOSPITALIZATION => true,
            default => false,
        };
    }

    /**
     * Grupo A do contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md
     * §2: clínico padrão, exige `MedicalRecord` com peso + (diagnóstico OU plano) para
     * finalizar — distinto do Grupo B (`VACCINATION`, regra própria de finalização) e do
     * Grupo C (`isExam()`, vira `Exam`, nunca `MedicalRecord`). `nutrition`/`behavioral`
     * entram aqui só ESTRUTURALMENTE — `isVeterinarianGated()` decide, à parte (precisa
     * saber quem executa), se o ramo concreto é clínico ou não.
     */
    public function isGroupA(): bool
    {
        return match ($this) {
            self::CONSULTATION, self::EMERGENCY, self::SURGERY, self::DENTAL, self::REHABILITATION => true,
            self::NUTRITION, self::BEHAVIORAL => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function groupA(): array
    {
        return array_values(array_filter(self::cases(), fn (self $category): bool => $category->isGroupA()));
    }
}
