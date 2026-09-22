<?php

namespace App\Services\Document;

use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Renderização de `document_templates.body_html` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regra de negócio 2. **Bloqueante de
 * segurança** (revisão do `security-specialist` obrigatória, per spec): NUNCA um engine de
 * template genérico (Blade/Twig) sobre texto escrito por usuário da clínica — só
 * substituição literal de uma whitelist fixa de chaves conhecidas
 * (`strtr()`, não `eval()`/`Blade::render()`/`preg_replace_callback` com `/e`).
 *
 * Qualquer `{{algo_fora_da_whitelist}}` ou tentativa de expressão (`{{ 7*7 }}`,
 * `<?php ... ?>`) permanece literal no HTML de saída — nunca é avaliada. Os valores
 * substituídos passam por `htmlspecialchars()` (defesa em profundidade contra um nome de
 * pet/tutor que contenha HTML/script).
 */
final class DocumentTemplateRenderer
{
    public function render(string $bodyHtml, Pet $pet, User $professional, ?Organization $organization, CarbonInterface $date): string
    {
        return strtr($bodyHtml, $this->placeholders($pet, $professional, $organization, $date));
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(Pet $pet, User $professional, ?Organization $organization, CarbonInterface $date): array
    {
        $pet->loadMissing('user');
        $professional->loadMissing('professional');

        return [
            '{{pet.nome}}' => $this->escape($pet->name),
            '{{tutor.nome}}' => $this->escape($pet->user?->name ?? '—'),
            '{{profissional.nome}}' => $this->escape($professional->name),
            '{{profissional.crmv}}' => $this->escape($this->crmvOf($professional)),
            '{{clinica.nome}}' => $this->escape($organization?->name ?? '—'),
            '{{data}}' => $date->format('d/m/Y'),
        ];
    }

    private function crmvOf(User $professional): string
    {
        $crmv = $professional->professional?->crmv;
        if ($crmv === null) {
            return '—';
        }

        $state = $professional->professional?->crmv_state;

        return $state !== null ? "{$crmv}/{$state}" : $crmv;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
