<?php

namespace App\Enums\Registration;

/**
 * Obrigatoriedade de um campo do cadastro de profissional PARA UM TIPO ESPECÍFICO
 * (`ProfessionalCapabilityRegistry`). A ausência de valor (`null`, fora deste enum) significa
 * "não se aplica" — o campo nem deveria aparecer na tela para aquele tipo.
 *
 * Ver `docs/segmentacao-cadastro-profissional.md` §2, convenção ✅/➕/⬛.
 */
enum RequirementLevel: string
{
    case REQUIRED = 'required';
    case OPTIONAL = 'optional';
}
