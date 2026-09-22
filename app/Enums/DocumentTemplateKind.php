<?php

namespace App\Enums;

/** Contrato docs/gap-simplesvet/specs/15-modelos-documento-receituario-assinatura-spec.md. */
enum DocumentTemplateKind: string
{
    case CERTIFICATE = 'certificate';
    case CONSENT_FORM = 'consent_form';
    case REFERRAL = 'referral';
    case DEATH_DECLARATION = 'death_declaration';
    case HEALTH_CERTIFICATE = 'health_certificate';
    case GENERIC = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::CERTIFICATE => 'Atestado',
            self::CONSENT_FORM => 'Termo de consentimento',
            self::REFERRAL => 'Encaminhamento',
            self::DEATH_DECLARATION => 'Declaração de óbito',
            self::HEALTH_CERTIFICATE => 'Atestado sanitário',
            self::GENERIC => 'Genérico',
        };
    }

    /**
     * Regra de negócio 1 da spec 15: conteúdo clínico só é emitido por quem pratica ato
     * clínico (`User::isVeterinarian()`). Documento puramente administrativo (recibo,
     * termo genérico) não tem essa restrição.
     */
    public function requiresClinicalIssuer(): bool
    {
        return match ($this) {
            self::CERTIFICATE, self::REFERRAL, self::DEATH_DECLARATION, self::HEALTH_CERTIFICATE => true,
            self::CONSENT_FORM, self::GENERIC => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
