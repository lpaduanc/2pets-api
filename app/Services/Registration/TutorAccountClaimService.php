<?php

namespace App\Services\Registration;

use App\DataTransferObjects\Registration\TutorAccountClaimResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Caminho MANUAL de reivindicação — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6, "o caminho manual
 * continua existindo e não é opcional".
 *
 * Cenário: alguém se autocadastra (`POST /register`, ganha uma conta própria com senha) e, ao
 * completar o perfil de tutor, digita um CPF que já pertence a uma conta NÃO reivindicada
 * (criada pelo fluxo de paciente novo — `password IS NULL`, `registration_status = pending`).
 * Sem tratamento, isso batia no índice único de `cpf` e virava 422 "CPF já cadastrado" — o
 * exato erro que o contrato proíbe: a pessoa PRECISA continuar aquele cadastro, não ver um erro
 * de duplicidade.
 *
 * A conta "casca" (`$shellUser`, criada pelo autocadastro, sem CPF/pet) nunca é a que sobrevive:
 * o registro alvo já carrega histórico (pet, `PetVetAccess`, `ProfessionalClient`) que seria
 * fragmentado se a pessoa continuasse numa conta nova. A casca herda a senha do alvo e é
 * soft-deletada; o alvo assume dali para frente.
 */
final class TutorAccountClaimService
{
    /**
     * Sem colisão: devolve o próprio `$shellUser` inalterado, para o restante do fluxo de
     * conclusão de cadastro seguir exatamente como sempre seguiu.
     */
    public function resolve(User $shellUser, string $cpf): TutorAccountClaimResult
    {
        $target = $this->findUnclaimedAccountByCpf($cpf, excludingUserId: $shellUser->id);

        if ($target === null) {
            return new TutorAccountClaimResult($shellUser, claimed: false, newAccessToken: null);
        }

        return DB::transaction(fn () => $this->claim($shellUser, $target));
    }

    private function findUnclaimedAccountByCpf(string $cpf, int $excludingUserId): ?User
    {
        return User::where('cpf', $cpf)
            ->where('id', '!=', $excludingUserId)
            ->whereNull('password')
            ->where('registration_status', 'pending')
            ->first();
    }

    private function claim(User $shellUser, User $target): TutorAccountClaimResult
    {
        // A senha/e-mail da casca são lidos ANTES de soft-deletá-la, mas a exclusão em si
        // precisa acontecer ANTES de gravar o e-mail no alvo: o índice único de `email` é
        // parcial (`WHERE deleted_at IS NULL`) e as duas linhas ainda estariam "vivas" ao
        // mesmo tempo se a ordem fosse invertida — a UPDATE do alvo estouraria o índice.
        $shellPasswordHash = $shellUser->getRawOriginal('password');
        $shellEmail = $shellUser->email;
        $shellEmailVerifiedAt = $shellUser->email_verified_at;

        $shellUser->tokens()->delete();
        $shellUser->delete();

        $target->forceFill([
            'password' => $shellPasswordHash,
            'email' => $target->email ?? $shellEmail,
            'email_verified_at' => $target->email_verified_at ?? $shellEmailVerifiedAt,
            'registration_status' => 'approved',
        ])->save();

        $newAccessToken = $target->createToken('auth_token')->plainTextToken;

        return new TutorAccountClaimResult($target, claimed: true, newAccessToken: $newAccessToken);
    }
}
