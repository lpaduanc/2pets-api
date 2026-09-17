<?php

namespace App\Services\Professional;

use App\DataTransferObjects\Professional\TutorResolution;
use App\Models\User;
use App\Services\Organization\UserRoleReconciler;
use Illuminate\Support\Facades\Log;

/**
 * Identidade do fluxo de paciente novo — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §3. CPF é a chave forte;
 * e-mail é canal de contato opcional, nunca identidade.
 */
final class TutorIdentityResolver
{
    public function __construct(
        private readonly UserRoleReconciler $roleReconciler,
    ) {}

    /**
     * @param  array{name: string, email: ?string, phone: ?string}  $data  $data['cpf'] já limpo
     *                                                                     (só dígitos) pelo Form Request.
     */
    public function resolve(string $cpf, array $data): TutorResolution
    {
        $existing = User::where('cpf', $cpf)->first();

        if ($existing !== null) {
            return $this->reuseExisting($existing, $data);
        }

        return $this->createUnclaimed($cpf, $data);
    }

    /**
     * Regra §3.2/§3.4: e-mail colidindo com outro CPF é ignorado (nunca anexado, nunca usado
     * para enviar nada); tutor que já tinha e-mail e veio sem mantém o que já existe. Nenhum
     * dos dois casos atualiza o e-mail gravado — só o e-mail AUSENTE em ambos os lados nasce
     * preenchido.
     *
     * @param  array{name: string, email: ?string, phone: ?string}  $data
     */
    private function reuseExisting(User $existing, array $data): TutorResolution
    {
        $emailConflict = $this->emailBelongsToSomeoneElse($data['email'] ?? null, $existing->id);

        if ($emailConflict) {
            $this->logEmailConflictAlert($existing, $data['email']);
        }

        if ($existing->email === null && ! $emailConflict && ! empty($data['email'])) {
            $existing->update(['email' => $data['email']]);
        }

        return new TutorResolution($existing, isNewAccount: false, emailIgnoredDueToConflict: $emailConflict);
    }

    /**
     * Nasce SEM senha (`password IS NULL`) e `registration_status = pending` — o par que
     * `User::isUnclaimed()` reconhece como "conta ainda não reivindicada" (contrato §2).
     *
     * @param  array{name: string, email: ?string, phone: ?string}  $data
     */
    private function createUnclaimed(string $cpf, array $data): TutorResolution
    {
        $emailConflict = $this->emailBelongsToSomeoneElse($data['email'] ?? null, excludingUserId: null);

        if ($emailConflict) {
            $this->logEmailConflictAlert(null, $data['email']);
        }

        $user = User::create([
            'name' => $data['name'],
            'cpf' => $cpf,
            'email' => $emailConflict ? null : ($data['email'] ?? null),
            'phone' => $data['phone'] ?? null,
            'password' => null,
            'user_type' => 'tutor',
            'role' => 'tutor',
            'registration_status' => 'pending',
            'profile_completed' => false,
        ]);

        $this->reconcileRole($user);

        return new TutorResolution($user, isNewAccount: true, emailIgnoredDueToConflict: $emailConflict);
    }

    private function emailBelongsToSomeoneElse(?string $email, ?int $excludingUserId): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return User::where('email', $email)
            ->when($excludingUserId !== null, fn ($query) => $query->where('id', '!=', $excludingUserId))
            ->exists();
    }

    /**
     * Mesmo fallback de `ClientProvisioningService`: papel Spatie pode não existir ainda se o
     * seeder não rodou — a conta continua criada, só fica sem acesso a nada (403 seguro).
     */
    private function reconcileRole(User $user): void
    {
        try {
            $this->roleReconciler->reconcile($user);
        } catch (\Throwable $failure) {
            Log::warning('Could not assign tutor role to new-patient tutor account', [
                'user_id' => $user->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * Não há mesa de suporte estruturada no projeto hoje — o canal existente para "alguém
     * precisa olhar isto manualmente" é o log de aplicação (mesmo padrão usado para outras
     * situações que exigem revisão humana). `tutor_id` vem nulo quando o conflito acontece na
     * CRIAÇÃO (ainda não existe id para logar).
     */
    private function logEmailConflictAlert(?User $tutor, ?string $conflictingEmail): void
    {
        Log::warning('E-mail informado no agendamento de paciente novo pertence a outro CPF — ignorado', [
            'tutor_id' => $tutor?->id,
            'tutor_cpf' => $tutor?->cpf,
            'conflicting_email' => $conflictingEmail,
        ]);
    }
}
