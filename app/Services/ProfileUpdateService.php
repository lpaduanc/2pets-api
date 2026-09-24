<?php

namespace App\Services;

use App\Models\User;
use App\Services\Location\UserAddressGeocoder;
use App\Services\Profile\LinkedProfileUpdateService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma atualizacao de perfil de usuario: troca de senha (quando
 * pedida), re-geocodificacao do endereco (quando ele muda) e atualizacao dos
 * blocos aninhados `professional`/`company` (quando o usuario ja tem um
 * registro correspondente).
 *
 * Sem a re-geocodificacao, `PUT /profile` gravava o endereco textual novo mas
 * deixava latitude/longitude — e por tabela, a coluna `location` (via
 * HasGeoPoint) — apontando para o endereco antigo, permanentemente. Ver bug
 * registrado no plano de otimizacao de performance, Fase 2.
 */
final class ProfileUpdateService
{
    /** @var list<string> */
    private const PASSWORD_INPUT_FIELDS = ['current_password', 'new_password', 'new_password_confirmation'];

    public function __construct(
        private readonly UserAddressGeocoder $addressGeocoder,
        private readonly LinkedProfileUpdateService $linkedProfileUpdateService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Dados ja validados pelo Form Request
     */
    public function update(User $user, array $data): User
    {
        $data = $this->applyPasswordChange($user, $data);
        [$userData, $professionalData, $companyData] = $this->extractNestedPayloads($data);
        $userData = $this->applyGeocoding($user, $userData);

        DB::transaction(function () use ($user, $userData, $professionalData, $companyData): void {
            $user->update($userData);
            $this->linkedProfileUpdateService->update($user, $professionalData, $companyData);
        });

        $this->addressGeocoder->scheduleRetryIfFailed($user);

        return $user->fresh(['professional', 'company']);
    }

    /**
     * Separa os blocos `professional`/`company` (que vao para outras
     * tabelas) e achata `address` (aninhado) nas colunas de `users`.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     */
    private function extractNestedPayloads(array $data): array
    {
        $professionalData = Arr::pull($data, 'professional');
        $companyData = Arr::pull($data, 'company');
        $addressData = Arr::pull($data, 'address');

        return [$this->flattenAddress($data, $addressData), $professionalData, $companyData];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $address
     * @return array<string, mixed>
     */
    private function flattenAddress(array $data, ?array $address): array
    {
        if ($address === null) {
            return $data;
        }

        $mapped = array_filter([
            'address' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'complement' => $address['complement'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip_code'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        return [...$data, ...$mapped];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyPasswordChange(User $user, array $data): array
    {
        if (! isset($data['current_password'])) {
            return Arr::except($data, self::PASSWORD_INPUT_FIELDS);
        }

        if (! isset($data['new_password'])) {
            throw ValidationException::withMessages([
                'new_password' => ['A nova senha e obrigatoria quando a senha atual e informada.'],
            ]);
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Senha atual incorreta'],
            ]);
        }

        $data['password'] = Hash::make($data['new_password']);

        return Arr::except($data, self::PASSWORD_INPUT_FIELDS);
    }

    /**
     * Endereço alterado → coordenada nova. Falhou? O endereço é salvo mesmo assim, com a
     * coordenada ANULADA e `geocoding_status = failed` — nunca o ponto do endereço antigo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyGeocoding(User $user, array $data): array
    {
        if (! $this->addressChanged($data)) {
            return $data;
        }

        return [...$data, ...$this->addressGeocoder->coordinateAttributes($this->mergedAddress($user, $data))];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addressChanged(array $data): bool
    {
        return array_intersect(UserAddressGeocoder::ADDRESS_FIELDS, array_keys($data)) !== [];
    }

    /**
     * Endereço completo pós-edição: o que veio no request sobre o que já estava gravado.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergedAddress(User $user, array $data): array
    {
        return [
            ...$user->only(UserAddressGeocoder::ADDRESS_FIELDS),
            ...array_intersect_key($data, array_flip(UserAddressGeocoder::ADDRESS_FIELDS)),
        ];
    }
}
