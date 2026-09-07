<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparo de dados: `medical_records.symptoms` gravado duplo-encodado.
 *
 * Mesmo defeito já corrigido em `prescriptions.medications`: o model castea `symptoms` como
 * `array` (o Eloquent já serializa) e o `MedicalDataSeeder` ainda chamava `json_encode()` antes
 * de entregar o valor. O banco guarda uma *string JSON dentro de JSON*
 * (`"[\"apatia\"]"` em vez de `["apatia"]`), e a tela de prontuários itera os caracteres da
 * string — exatamente o que quebrou a tela de prescrições. O seeder foi corrigido junto.
 *
 * A lógica é deliberadamente uma cópia da migration de `medications`, não uma classe
 * compartilhada: migration é retrato de um momento e precisa continuar rodando igual daqui a
 * anos, mesmo que a regra de negócio mude. Depender de classe de `app/` acopla o histórico do
 * banco a código vivo.
 *
 * Propriedades (as mesmas da irmã):
 *   - **Idempotente**: predicado `json_typeof(symptoms) = 'string'`; linha já correta não é
 *     tocada e a segunda execução não muda nada.
 *   - **Sem perda silenciosa**: se o valor desencapsulado não for array, aborta com o id.
 *   - **`chunkById`**: o UPDATE tira a linha do predicado, e paginação por OFFSET pularia linhas.
 *
 * `down()` vazio de propósito: reverter seria recriar o bug.
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

        DB::table('medical_records')
            ->select('id', 'symptoms')
            ->whereRaw("json_typeof(symptoms) = 'string'")
            ->chunkById(self::CHUNK_SIZE, function ($rows): void {
                foreach ($rows as $row) {
                    $this->repairRow((int) $row->id, (string) $row->symptoms);
                }
            });
    }

    /**
     * Sem rollback: o estado anterior é o próprio defeito. Reverter significaria voltar a
     * gravar string JSON dentro de JSON.
     */
    public function down(): void {}

    private function repairRow(int $id, string $rawValue): void
    {
        $symptoms = $this->unwrap($rawValue);

        if (! is_array($symptoms)) {
            throw new RuntimeException(
                "Abortado: medical_records.id={$id} tem `symptoms` que não decodifica para um array. Corrija a linha manualmente antes de rodar esta migration."
            );
        }

        DB::table('medical_records')
            ->where('id', $id)
            ->update(['symptoms' => json_encode($symptoms)]);
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
