<?php

namespace App\Enums\Registration;

/**
 * Catálogo de equipamento clínico (`docs/segmentacao-cadastro-profissional.md` §4.1,
 * revisado por `docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.1) — `clinic` e
 * `laboratory` declaram o catálogo inteiro; `vet` (veterinário volante) declara um
 * subconjunto portátil (ver `ProfessionalCapabilityDefinitions::all()['vet']['equipment']`).
 * Espelha `2pets-app/src/constants/professionalOptions.js` (`EQUIPMENT`); os rótulos aqui são
 * a fonte única — o front consome via `GET /register/professional-schema`, não duplica.
 *
 * Decisão deliberada (`equipamento-vet-volante-e-marketplace-b2b.md` §2.1): um único enum
 * compartilhado, sem um `PortableEquipment` paralelo. `XRAY_MACHINE`/`ULTRASOUND_MACHINE`/
 * `ECG_MACHINE` representam o mesmo conceito clínico esteja o aparelho fixo numa sala de
 * clínica ou portátil na maleta do volante — o tutor que busca "tem ultrassom" quer saber a
 * mesma coisa nos dois casos. Fixo vs. portátil não é modelado como atributo porque nenhuma
 * regra do produto hoje precisa distinguir os dois (nem busca, nem preço, nem dependência de
 * serviço) — se essa distinção nascer, o lugar certo é um atributo no vínculo
 * profissional↔equipamento, não um segundo catálogo.
 */
enum ClinicalEquipment: string
{
    case XRAY_MACHINE = 'xray_machine';
    case ULTRASOUND_MACHINE = 'ultrasound_machine';
    case ECG_MACHINE = 'ecg_machine';
    case SURGERY_ROOM = 'surgery_room';
    case ICU = 'icu';
    case LABORATORY = 'laboratory';
    case PHARMACY = 'pharmacy';
    case KENNELS = 'kennels';
    case OXYGEN_THERAPY = 'oxygen_therapy';
    case DENTAL_EQUIPMENT = 'dental_equipment';
    case ANESTHESIA_MACHINE = 'anesthesia_machine';
    case AMBULANCE = 'ambulance';
    // Quatro itens novos (§1.2/§2.1 do documento de equipamento do volante) — nenhum existia
    // em lugar nenhum do catálogo antes. `clinic`/`laboratory` os recebem de graça via
    // `$allEquipment` em `ProfessionalCapabilityDefinitions`, sem entrada manual.
    case PORTABLE_MULTIPARAMETER_MONITOR = 'portable_multiparameter_monitor';
    case PORTABLE_OXYGEN_THERAPY = 'portable_oxygen_therapy';
    case VASCULAR_DOPPLER = 'vascular_doppler';
    case POINT_OF_CARE_ANALYZER = 'point_of_care_analyzer';

    /**
     * Resolvido via `lang/{locale}/registration.php` (`clinical_equipment.*`) — pt-BR é o
     * default de `App::getLocale()`; só a rota do schema de cadastro troca o locale por
     * request (ver `App\Http\Middleware\SetLocaleFromAcceptLanguage`). A mensagem da dupla
     * trava em `ServiceEquipmentDependency` também chama este método, mas continua em pt-BR
     * de propósito — ver relato da tarefa de i18n do cadastro.
     */
    public function label(): string
    {
        return __('registration.clinical_equipment.'.$this->value);
    }

    /**
     * Vínculo equipamento↔documento (`equipamento-vet-volante-e-marketplace-b2b.md` §1.3/§4):
     * raio-X é o único item com fricção regulatória real hoje (radiação ionizante — alvará
     * sanitário/registro do equipamento, não CRMV). O documento é por ITEM de equipamento, não
     * por perfil inteiro — se um item novo ganhar exigência própria no futuro, o mapa cresce
     * aqui, não vira `if` espalhado. `DocumentController`/`UploadDocumentRequest` aceitam este
     * `document_type`; a aprovação em si (badge "verificado" por item) fica pendente de
     * confirmação jurídica (nível de confiança médio, ver documento) e não bloqueia o cadastro.
     */
    public function requiredDocumentType(): ?string
    {
        return match ($this) {
            self::XRAY_MACHINE => 'radiology_license',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
