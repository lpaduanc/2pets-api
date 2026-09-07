<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparo de dados: `prescriptions.medications` gravado duplo-encodado.
 *
 * Causa: `PrescriptionController::store()/update()` faziam `json_encode($data['medications'])`
 * ANTES de entregar ao Eloquent, que já serializa por conta do cast `'medications' => 'array'`.
 * O resultado no banco é uma *string JSON dentro de JSON*
 * (`"[{\"name\":\"Amoxicilina\"}]"` em vez de `[{"name":"Amoxicilina"}]`), e todo cliente que
 * itera o valor acaba iterando os caracteres da string. O `MedicalDataSeeder` repetia o mesmo
 * erro. Ambos corrigidos junto com esta migration.
 *
 * A migration desencapsula as linhas afetadas. Propriedades:
 *   - **Idempotente**: o predicado é `json_typeof(medications) = 'string'`; linha já correta
 *     (`array`/`object`) não é tocada, e rodar duas vezes não muda nada na segunda.
 *   - **Sem perda silenciosa**: se o conteúdo desencapsulado não for um array (indício de que a
 *     coluna guarda outra coisa, não uma receita), aborta com o id da linha em vez de gravar
 *     lixo por cima.
 *   - **`chunkById`**, não `chunk`: o UPDATE faz a linha deixar de casar o predicado, e a
 *     paginação por OFFSET puraria linhas.
 *
 * O `down()` é intencionalmente vazio: reintroduzir o duplo-encode seria recriar o bug.
 */
return new class extends Migration
{
    /** Linhas por lote. Alto o bastante para ser rápido, baixo o bastante para não segurar memória. */
    private const CHUNK_SIZE = 500;

    /** Teto de desencapsulamento — protege contra loop caso o valor seja uma string aninhada. */
    private const MAX_ENCODING_DEPTH = 5;

    public function up(): void
    {
        // `json_typeof` é específico do PostgreSQL e a coluna é `json` nativo.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('prescriptions')
            ->select('id', 'medications')
            ->whereRaw("json_typeof(medications) = 'string'")
            ->chunkById(self::CHUNK_SIZE, function ($rows): void {
                foreach ($rows as $row) {
                    $this->repairRow((int) $row->id, (string) $row->medications);
                }
            });
    }

    /**
     * Sem rollback: o estado anterior é o próprio defeito. Reverter significaria voltar a
     * gravar string JSON dentro de JSON, que é exatamente o que quebrava a tela.
     */
    public function down(): void {}

    private function repairRow(int $id, string $rawValue): void
    {
        $medications = $this->unwrap($rawValue);

        if (! is_array($medications)) {
            throw new RuntimeException(
                "Abortado: prescriptions.id={$id} tem `medications` que não decodifica para um array. Corrija a linha manualmente antes de rodar esta migration."
            );
        }

        DB::table('prescriptions')
            ->where('id', $id)
            ->update(['medications' => json_encode($medications)]);
    }

    /** Remove quantas camadas de `json_encode` estiverem empilhadas sobre o valor real. */
    private function unwrap(string $rawValue): mixed
    {
        $value = json_decode($rawValue, true);
        $depth = 0;

        while (is_string($value) && $depth < self::MAX_ENCODING_DEPTH) {
            $value = json_decode($value, true);
            $depth++;
        }

        return $value;
    }
};
