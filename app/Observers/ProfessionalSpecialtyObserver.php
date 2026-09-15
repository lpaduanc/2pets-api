<?php

namespace App\Observers;

use App\Models\Professional;
use App\Services\Professional\SpecialtyCatalogIndex;

/**
 * Mantém a pivô `professional_specialty` igual ao que está em `professionals.specialties`.
 *
 * ── Por que observer, e não uma chamada explícita no service ──────────────────────────
 * Porque a escrita de `specialties` acontece em mais de um lugar — conclusão de cadastro de
 * veterinário, `PUT /api/profile`, e o que mais nascer — e a regra aqui não é de NEGÓCIO, é
 * de COERÊNCIA entre duas representações do mesmo fato durante a transição. Uma chamada
 * explícita em cada service é uma chamada que alguém esquece no próximo caminho de escrita,
 * e o sintoma seria um profissional que existe na coluna e não na pivô: invisível até a
 * busca passar a ler a pivô e ele sumir.
 *
 * ⚠️ `wasChanged()` NÃO cobre `create()` — por isso `created` sincroniza sempre e só
 * `updated` verifica a mudança. É a mesma armadilha registrada em
 * `agent-memory/backend-specialist/postgis.md`.
 *
 * ⚠️ Escrita em MASSA por query builder (`Professional::where(...)->update()`, e os `insert`
 * em lote dos seeders) NÃO dispara evento Eloquent e portanto não passa por aqui. Para esses
 * casos existe `App\Support\Catalog\SpecialtyPivotBackfill`, que é a mesma regra em SQL.
 *
 * A coluna continua sendo escrita normalmente: enquanto a busca e os Resources a lerem, ela
 * é a fonte, e a pivô é a garantia de integridade referencial que ela nunca teve.
 */
final class ProfessionalSpecialtyObserver
{
    public function __construct(private readonly SpecialtyCatalogIndex $catalog) {}

    public function created(Professional $professional): void
    {
        $this->sync($professional);
    }

    public function updated(Professional $professional): void
    {
        if (! $professional->wasChanged('specialties')) {
            return;
        }

        $this->sync($professional);
    }

    /**
     * `sync()` (e não `attach()`) porque a lista declarada é o estado COMPLETO: especialidade
     * retirada do cadastro precisa sair da pivô, senão o profissional continua aparecendo
     * numa busca por algo que ele não declara mais.
     */
    private function sync(Professional $professional): void
    {
        $professional->catalogSpecialties()->sync($this->catalog->idsFor($this->declaredValues($professional)));
    }

    /**
     * A coluna é TEXT com JSON dentro e o cast devolve o que estiver lá — inclusive `null`
     * ou item não textual, se alguma escrita antiga tiver deixado. Só string entra.
     *
     * @return list<string>
     */
    private function declaredValues(Professional $professional): array
    {
        $declared = $professional->specialties;

        return is_array($declared)
            ? array_values(array_filter($declared, static fn (mixed $value): bool => is_string($value)))
            : [];
    }
}
