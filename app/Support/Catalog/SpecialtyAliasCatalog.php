<?php

namespace App\Support\Catalog;

/**
 * As grafias de especialidade que os CLIENTES mandam e que não são o `name` do catálogo.
 *
 * Mora fora de `VeterinarySpecialtyCatalog` de propósito: aquele arquivo é a taxonomia
 * veterinária canônica (o que existe como especialidade, e sob que ato ela é exercida), e
 * `cardiology`/`general` não são taxonomia — são o formato que o formulário do app fala
 * (`2pets-app/src/constants/professionalOptions.js`, const `SPECIALTIES`). Misturar slug de
 * cliente com o catálogo faria a lista de especialidades do país carregar o histórico de um
 * frontend.
 *
 * ── Por que aceitar, em vez de exigir a grafia do catálogo ────────────────────────────
 * Porque a escrita fechada no catálogo, sem estes aliases, fecha o cadastro de veterinário:
 * o formulário manda `["cardiology","dentistry","general"]` e a API devolve 422 em todos.
 * Alias não afrouxa a regra — o valor continua tendo que virar UMA linha de `specialties`;
 * só reconhece que o mesmo conceito tem um nome em inglês no cliente.
 *
 * ── `emergency` é o caso que NÃO tem alvo ─────────────────────────────────────────────
 * "Emergência 24h" está na lista de especialidades do formulário por erro de taxonomia:
 * emergência não é especialidade, é disponibilidade de atendimento. O backend já a modela
 * duas vezes e nos lugares certos — `ServiceCategory::EMERGENCY` (serviço prestado) e
 * `ProfessionalDifferential::EMERGENCY_AVAILABLE`/`EMERGENCY_24H`, que gravam
 * `professionals.emergency_available` e `emergency_24h`. Criar uma 22ª linha "Emergência"
 * em `specialties` só para o alias fechar inventaria uma especialidade que o CFMV não
 * reconhece e que passaria a concorrer, na busca, com o dado verdadeiro que já existe.
 *
 * Por isso o valor é DESCARTADO na normalização de entrada, e não rejeitado: recusar com 422
 * mantém a porta fechada para quem marcou a opção que o próprio formulário oferece, por um
 * erro que é nosso. O descarte é enumerado (esta lista, e só ela) e registrado em log com o
 * usuário — nunca um filtro genérico de "o que não reconheço, eu sumo".
 */
final class SpecialtyAliasCatalog
{
    /**
     * Slug do formulário => `name` da linha de `specialties`. Todo alvo é conferido contra
     * `VeterinarySpecialtyCatalog` por teste; alvo inexistente não resolveria nada e voltaria
     * a dar 422 sem aviso nenhum.
     *
     * @var array<string, string>
     */
    private const CANONICAL_BY_ALIAS = [
        'general' => 'Clinica Geral',
        'cardiology' => 'Cardiologia',
        'dermatology' => 'Dermatologia',
        'orthopedics' => 'Ortopedia',
        'ophthalmology' => 'Oftalmologia',
        'dentistry' => 'Odontologia Veterinaria',
        'neurology' => 'Neurologia',
        'oncology' => 'Oncologia',
        'surgery' => 'Cirurgia Geral',
        'anesthesiology' => 'Anestesiologia',
        'exotic' => 'Medicina de Animais Silvestres/Exoticos',
        'nutrition' => 'Nutricao Animal',
        'reproduction' => 'Reproducao Animal',
    ];

    /**
     * Valores que o formulário manda como especialidade, que NÃO são especialidade e que o
     * backend já representa em outro campo. Descartados na entrada, com log.
     *
     * @var list<string>
     */
    private const MISFILED_ALIASES = ['emergency'];

    /**
     * @return array<string, string>
     */
    public static function canonicalNameByAlias(): array
    {
        return self::CANONICAL_BY_ALIAS;
    }

    /**
     * @return list<string>
     */
    public static function misfiledAliases(): array
    {
        return self::MISFILED_ALIASES;
    }
}
