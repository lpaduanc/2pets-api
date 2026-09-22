<?php

namespace App\Services\Purchase;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Próximo número visível (`code`) de um documento, por dono — mesma regra do
 * `SaleService::nextNumber`: `MAX + 1` sob `pg_advisory_xact_lock` por dono dentro da transação
 * de criação, com o índice único parcial da tabela como garantia final. Advisory lock e não
 * `FOR UPDATE`: o Postgres recusa `FOR UPDATE` com agregado, e travar linhas não seguraria o
 * primeiro documento de um dono que ainda não tem nenhum.
 */
final class OwnerSequence
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    public function next(string $modelClass, array $ownership, string $column = 'code'): int
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::withTrashed();

        $table = (new $modelClass)->getTable();

        if ($ownership['organization_id'] !== null) {
            $query->where('organization_id', $ownership['organization_id']);
            $lockKey = "{$table}_{$column}:organization:{$ownership['organization_id']}";
        } else {
            $query->where('professional_id', $ownership['professional_id'])->whereNull('organization_id');
            $lockKey = "{$table}_{$column}:professional:{$ownership['professional_id']}";
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
        }

        return ((int) $query->max($column)) + 1;
    }
}
