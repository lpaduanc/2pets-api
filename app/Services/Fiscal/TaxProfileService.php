<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de perfil tributário — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §2/§6.
 * Escrita multi-tabela (perfil + faixas de CFOP) sempre em transação; marcar um perfil como
 * padrão desmarca o anterior do mesmo dono ANTES (o índice único parcial não aceita dois).
 */
final class TaxProfileService
{
    /** @param  array<string, mixed>  $data  validado por `StoreTaxProfileRequest` */
    public function create(FiscalOwner $owner, array $data): TaxProfile
    {
        return DB::transaction(function () use ($owner, $data): TaxProfile {
            $this->releaseDefaultIfNeeded($owner, $data);
            $profile = TaxProfile::create(array_diff_key($data, ['cfops' => true]) + $owner->attributes());
            $this->syncCfops($profile, $data);

            return $profile->fresh('cfops');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(TaxProfile $profile, array $data): TaxProfile
    {
        return DB::transaction(function () use ($profile, $data): TaxProfile {
            $this->releaseDefaultIfNeeded(FiscalOwner::of($profile), $data, $profile->id);
            $profile->update(array_diff_key($data, ['cfops' => true]));
            $this->syncCfops($profile, $data);

            return $profile->fresh('cfops');
        });
    }

    /**
     * Perfil em uso por produto ou grupo não sai: os itens ficariam sem regra fiscal sem aviso.
     * Documento já emitido guarda o próprio snapshot — não conta como uso.
     */
    public function delete(TaxProfile $profile): void
    {
        $products = $profile->products()->count();
        $groups = $profile->productGroups()->count();

        abort_if($products + $groups > 0, 422, sprintf('Perfil em uso: %d produto(s) e %d grupo(s). Troque o perfil deles antes de excluir.', $products, $groups));

        $profile->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function releaseDefaultIfNeeded(FiscalOwner $owner, array $data, ?int $keepId = null): void
    {
        if (! ($data['is_default'] ?? false)) {
            return;
        }

        $owner->scope(TaxProfile::query())
            ->where('is_default', true)
            ->when($keepId !== null, fn ($query) => $query->whereKeyNot($keepId))
            ->update(['is_default' => false]);
    }

    /**
     * As faixas enviadas SUBSTITUEM as atuais (o formulário manda a tabela inteira). Sem a chave
     * `cfops` no PUT, nada muda.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCfops(TaxProfile $profile, array $data): void
    {
        if (! array_key_exists('cfops', $data)) {
            return;
        }

        $profile->cfops()->delete();
        $profile->cfops()->createMany(array_map(
            fn (array $cfop): array => ['operation' => $cfop['operation'], 'scope' => $cfop['scope'], 'cfop' => $cfop['cfop']],
            $data['cfops'] ?? [],
        ));
    }
}
