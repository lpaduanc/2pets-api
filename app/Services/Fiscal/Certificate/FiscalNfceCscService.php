<?php

namespace App\Services\Fiscal\Certificate;

use App\Enums\FiscalEnvironment;
use App\Models\FiscalNfceCsc;
use App\Models\Organization;
use App\Models\User;
use App\Support\Fiscal\FiscalSecretCipher;

/**
 * CSC da NFC-e por organização e ambiente — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §4. O token assina o QR Code do DANFCE (hash SHA-1 com o
 * CSC): quem o tem forja QR Code válido em nome da empresa, por isso é cifrado como o A1 e nunca
 * sai por endpoint — só o `csc_id` e o "configurado: sim/não".
 */
final class FiscalNfceCscService
{
    public function __construct(private readonly FiscalSecretCipher $cipher) {}

    public function save(Organization $organization, FiscalEnvironment $environment, string $cscId, #[\SensitiveParameter] string $token, User $user): void
    {
        $csc = FiscalNfceCsc::query()->where('organization_id', $organization->id)->where('environment', $environment->value)->first() ?? new FiscalNfceCsc;
        $csc->fill(['organization_id' => $organization->id, 'environment' => $environment->value, 'csc_id' => $cscId, 'updated_by' => $user->id]);
        $csc->token_ciphertext = $this->cipher->encrypt($token, self::context($organization->id, $environment));
        $csc->save();

        $this->audit($organization, $user, 'fiscal_nfce_csc.saved', 'CSC da NFC-e cadastrado', $environment, $cscId);
    }

    public function remove(Organization $organization, FiscalEnvironment $environment, User $user): void
    {
        $csc = FiscalNfceCsc::query()->where('organization_id', $organization->id)->where('environment', $environment->value)->first();
        abort_if($csc === null, 404, 'Não há CSC cadastrado para este ambiente.');
        $csc->delete();

        $this->audit($organization, $user, 'fiscal_nfce_csc.removed', 'CSC da NFC-e removido', $environment, $csc->csc_id);
    }

    public function has(int $organizationId, FiscalEnvironment $environment): bool
    {
        return FiscalNfceCsc::query()->where('organization_id', $organizationId)->where('environment', $environment->value)->exists();
    }

    /**
     * Id e token decifrados para montar o QR Code — só o driver da SEFAZ chama.
     *
     * @return array{id: string, token: string}|null
     */
    public function credentials(int $organizationId, FiscalEnvironment $environment): ?array
    {
        $csc = FiscalNfceCsc::query()->where('organization_id', $organizationId)->where('environment', $environment->value)->first();

        return $csc === null ? null : ['id' => $csc->csc_id, 'token' => $this->cipher->decrypt($csc->token_ciphertext, self::context($organizationId, $environment))];
    }

    /** @return array<string, array{csc_id: string, configured: bool, updated_at: string|null}|null> */
    public function summary(Organization $organization): array
    {
        $stored = FiscalNfceCsc::query()->where('organization_id', $organization->id)->get()->keyBy(fn (FiscalNfceCsc $csc): string => $csc->environment->value);

        return collect(FiscalEnvironment::cases())->mapWithKeys(fn (FiscalEnvironment $environment): array => [
            $environment->value => $stored->has($environment->value)
                ? ['csc_id' => $stored[$environment->value]->csc_id, 'configured' => true, 'updated_at' => $stored[$environment->value]->updated_at?->toIso8601String()]
                : null,
        ])->all();
    }

    private function audit(Organization $organization, User $user, string $event, string $description, FiscalEnvironment $environment, string $cscId): void
    {
        activity('fiscal')->performedOn($organization)->causedBy($user)->event($event)
            ->withProperties(['environment' => $environment->value, 'csc_id' => $cscId])
            ->log($description);
    }

    private static function context(int $organizationId, FiscalEnvironment $environment): string
    {
        return "fiscal_nfce_cscs:{$organizationId}:{$environment->value}";
    }
}
