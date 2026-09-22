<?php

namespace App\Services\Professional;

use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Auth\PasswordResetLinkService;
use App\Services\EmailVerificationService;
use App\Services\Organization\UserRoleReconciler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Um profissional pode cadastrar um cliente (tutor) em nome dele — ex.: recepção de clínica
 * cadastrando quem chega no balcão sem conta na plataforma. A conta nasce sem senha
 * utilizável por ninguém: a pessoa recebe o mesmo e-mail de verificação do autocadastro
 * (`AuthController::register`) mais um link de definição de senha
 * (`PasswordResetLinkService`), nunca uma senha padrão conhecida.
 *
 * Se o e-mail já pertence a alguém da plataforma, o cadastro apenas VINCULA à conta
 * existente em vez de tentar duplicar. Em qualquer um dos dois casos, um vínculo EXPLÍCITO
 * é gravado em `professional_clients` — sem ele, um cliente cadastrado manualmente só vira
 * visível na listagem do profissional quando surgir um agendamento, fatura ou grant de
 * acesso ao pet entre os dois, o que pode nunca acontecer (ver `ProfessionalClientController`).
 */
final class ClientProvisioningService
{
    private const RANDOM_PASSWORD_LENGTH = 32;

    public function __construct(
        private readonly UserRoleReconciler $roleReconciler,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly PasswordResetLinkService $passwordResetLinkService,
        private readonly TutorIdentityResolver $tutorIdentityResolver,
    ) {}

    /**
     * `cpf` opcional (Achado 1 de `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md`):
     * quando informado, a identidade é resolvida por CPF via `TutorIdentityResolver` — o
     * MESMO resolvedor do fluxo de paciente novo — em vez do caminho antigo por e-mail. Isso
     * evita o par de mecanismos paralelos e incompatíveis que existia antes: a mesma pessoa
     * cadastrada pelos dois caminhos com CPF/e-mail que não colidem não nasce em duas contas.
     *
     * @param  array{name: string, email: ?string, cpf: ?string, phone: ?string, address: ?string}  $data
     */
    public function provision(array $data, int $professionalId): User
    {
        $client = $this->resolveClient($data);

        $this->linkToProfessional($client, $professionalId);

        return $client;
    }

    /**
     * @param  array{name: string, email: ?string, cpf: ?string, phone: ?string, address: ?string}  $data
     */
    private function resolveClient(array $data): User
    {
        if (! empty($data['cpf'])) {
            return $this->tutorIdentityResolver->resolve($data['cpf'], [
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ])->user;
        }

        return User::where('email', $data['email'])->first() ?? $this->createTutorAccount($data);
    }

    /**
     * Idempotente: um cliente já vinculado (ou revinculado após ter sido removido da lista)
     * não gera linha duplicada — o índice parcial `professional_clients_live_unique` garante
     * isso no banco, este método só evita a viagem redundante ao banco no caminho feliz.
     */
    private function linkToProfessional(User $client, int $professionalId): void
    {
        $link = ProfessionalClient::withTrashed()->firstOrNew([
            'professional_id' => $professionalId,
            'client_id' => $client->id,
        ]);

        if ($link->trashed()) {
            $link->restore();

            return;
        }

        if (! $link->exists) {
            $link->save();
        }
    }

    private function createTutorAccount(array $data): User
    {
        $user = $this->persistTutor($data);

        $this->reconcileRole($user);
        $this->sendAccountSetupEmails($user);

        return $user;
    }

    /**
     * Mesmo fallback de `AuthController::register`: o papel Spatie pode não existir ainda se
     * o seeder de papéis não rodou no ambiente. A conta continua criada — sem papel ela só
     * fica sem acesso a nada (403 seguro), nunca com acesso indevido.
     */
    private function reconcileRole(User $user): void
    {
        try {
            $this->roleReconciler->reconcile($user);
        } catch (Throwable $failure) {
            Log::warning('Could not assign tutor role to professional-created client', [
                'user_id' => $user->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * Falha ao enviar um e-mail não pode impedir a criação do cliente: o profissional já
     * cadastrou a pessoa, e ela ainda pode pedir "esqueci minha senha" e reenviar a
     * verificação depois pelos fluxos normais.
     */
    private function sendAccountSetupEmails(User $user): void
    {
        try {
            $this->emailVerificationService->sendVerificationEmail($user);
        } catch (Throwable $failure) {
            Log::warning('Failed to send verification email for professional-created client', [
                'user_id' => $user->id,
                'error' => $failure->getMessage(),
            ]);
        }

        $this->passwordResetLinkService->send($user);
    }

    /**
     * @param  array{name: string, email: string, phone: ?string, address: ?string}  $data
     */
    private function persistTutor(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => bcrypt(Str::random(self::RANDOM_PASSWORD_LENGTH)),
            'user_type' => 'tutor',
            'role' => 'tutor',
            'registration_status' => 'approved',
            // Sem `email_verified_at`: `User::$emailVerified` (accessor) deriva `false`.
            'profile_completed' => false,
        ]);
    }
}
