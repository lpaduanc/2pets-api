<?php

namespace App\Http\Requests\Commercial;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de
 * negócio 7 — repasse a parceiro terceiro, sem regra automática.
 *
 * `partner_user_id` (achado do frontend, corrigido nesta rodada): aceitava `exists:users,id`
 * puro — QUALQUER usuário da plataforma, incluindo tutor, virava alvo válido de repasse.
 * A regra de negócio 7 aceita parceiro sem vínculo formal com a clínica (não dá para exigir
 * `organization_members`/`ProfessionalClient`), então a validação não pode ficar restrita à
 * equipe — mas pelo menos fecha o caso claramente incoerente (tutor, ou o próprio dono
 * pagando a si mesmo). Ver `App\Services\Commercial\PartnerCandidateFinder` para a busca
 * escopada que alimenta o autocomplete do app.
 */
class StorePartnerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'partner_user_id' => [
                'required',
                'integer',
                // `role` (`tutor|professional|admin`), não `user_type` — `User::VET_ROLES`
                // já registra que `user_type` é inconfiável em linha legada.
                Rule::exists('users', 'id')->where('role', '!=', 'tutor'),
                $this->notTheCallerThemselves(),
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function notTheCallerThemselves(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((int) $value === $this->user()?->id) {
                $fail('Você não pode registrar um repasse para si mesmo.');
            }
        };
    }
}
