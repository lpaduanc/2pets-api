<?php

namespace App\Services\Import;

use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Organization\UserRoleReconciler;
use Illuminate\Support\Str;

/**
 * Cria a conta do cliente importado (item 26 do backlog gap-simplesvet).
 *
 * Deliberadamente NÃO reaproveita `App\Services\Professional\ClientProvisioningService`:
 * aquele serviço dispara e-mail de verificação + link de definir senha a cada chamada — correto
 * para um cadastro manual isolado no balcão, mas proibido aqui (regra 5 da spec: "tutor
 * importado não ganha consentimento de comunicação por importação", nenhum e-mail automático
 * dispara para registro recém-importado). Duplicar a criação de `User`+`ProfessionalClient`
 * é o preço de não enviar e-mail em massa para milhares de linhas de planilha — mas o papel
 * Spatie continua obrigatório (`UserRoleReconciler`), sem ele a conta importada não autentica
 * nada como tutor (autorização é sempre o papel Spatie, nunca `users.role`).
 */
final class ImportedClientProvisioner
{
    private const RANDOM_PASSWORD_LENGTH = 32;

    public function __construct(private readonly UserRoleReconciler $roleReconciler) {}

    /**
     * @param  array{name: string, email: ?string, cpf: ?string, phone: ?string, address: ?string, birth_date: ?string}  $normalized
     */
    public function create(array $normalized, int $professionalId): User
    {
        $client = User::create([
            'name' => $normalized['name'],
            'email' => $normalized['email'],
            'cpf' => $normalized['cpf'],
            'phone' => $normalized['phone'],
            'address' => $normalized['address'],
            'birth_date' => $normalized['birth_date'] ?? null,
            'password' => bcrypt(Str::random(self::RANDOM_PASSWORD_LENGTH)),
            'user_type' => 'tutor',
            'role' => 'tutor',
            'registration_status' => 'approved',
            'profile_completed' => false,
        ]);

        $this->roleReconciler->reconcile($client);

        ProfessionalClient::create([
            'professional_id' => $professionalId,
            'client_id' => $client->id,
        ]);

        return $client;
    }
}
