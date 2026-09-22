<?php

namespace App\Services\Commercial;

use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Candidatos a `partner_user_id` de um repasse (item 09, achado do frontend) — busca
 * ESCOPADA por nome/e-mail, nunca a base de usuários inteira da plataforma
 * (`StorePartnerPayoutRequest` antes aceitava qualquer `exists:users,id`). Duas fontes
 * legítimas: membro ativo da organização do dono (ex.: recepcionista que também recebe
 * repasse por acordo à parte) e parceiro já usado antes neste mesmo escopo (`ownershipFor()`)
 * — cobre o caso comum de repasse recorrente ao mesmo vet volante sem reabrir busca ampla.
 *
 * Um parceiro NOVO, sem nenhuma das duas relações, não aparece aqui — a spec 09 (regra 7)
 * aceita que repasse seja lançado manualmente sem vínculo formal, e reduzir a validação a só
 * estas duas fontes fecharia esse caminho legítimo. Por isso `StorePartnerPayoutRequest`
 * continua aceitando qualquer usuário profissional (não-tutor) por id — ver comentário lá.
 */
final class PartnerCandidateFinder
{
    private const MAX_RESULTS = 20;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @return Collection<int, array{id: int, name: string, email: string, source: string}>
     */
    public function search(User $user, string $term): Collection
    {
        return $this->teamCandidates($user, $term)
            ->merge($this->previousPartnerCandidates($user, $term))
            ->unique('id')
            ->values()
            ->take(self::MAX_RESULTS);
    }

    /** @return Collection<int, array{id: int, name: string, email: string, source: string}> */
    private function teamCandidates(User $user, string $term): Collection
    {
        $teamUserIds = $this->scope->teamMembers($user)->pluck('user_id')->all();

        return $this->matching($teamUserIds, $term, 'team_member');
    }

    /** @return Collection<int, array{id: int, name: string, email: string, source: string}> */
    private function previousPartnerCandidates(User $user, string $term): Collection
    {
        $ownership = $this->scope->ownershipFor($user);

        $partnerIds = PartnerPayout::query()
            ->where('organization_id', $ownership['organization_id'])
            ->when(
                $ownership['organization_id'] === null,
                fn ($query) => $query->where('professional_id', $ownership['professional_id'])
            )
            ->distinct()
            ->pluck('partner_user_id')
            ->all();

        return $this->matching($partnerIds, $term, 'previous_partner');
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, array{id: int, name: string, email: string, source: string}>
     */
    private function matching(array $userIds, string $term, string $source): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%"))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $candidate): array => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $this->maskEmail($candidate->email),
                'source' => $source,
            ]);
    }

    /** A tela só precisa distinguir homônimos — não precisa do e-mail inteiro do colega. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visiblePrefix = mb_substr($local, 0, 1);

        return $visiblePrefix.str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
