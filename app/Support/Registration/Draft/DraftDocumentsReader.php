<?php

namespace App\Support\Registration\Draft;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Documentos já enviados pelo usuário (RG, CPF, diploma, foto profissional) — os 3 fluxos de
 * rascunho devolvem a mesma lista para reidratar a tela de upload, então a query mora aqui
 * uma vez só em vez de repetida em cada Repository.
 */
final class DraftDocumentsReader
{
    /** @return list<object> */
    public function forUser(User $user): array
    {
        return DB::table('documents')
            ->where('user_id', $user->id)
            ->select('id', 'document_type', 'file_path', 'file_name', 'created_at')
            ->get()
            ->all();
    }
}
