<?php

namespace App\Http\Requests\Insights;

/**
 * `GET insights/{indicator}/drill-down` e `.../export` — mesmos filtros de
 * `InsightQueryRequest`, mais o `bucket` (o ponto do gráfico clicado; `null` exporta/lista o
 * período inteiro) e a paginação do drill-down.
 */
class InsightDrillDownRequest extends InsightQueryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'bucket' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function bucket(): ?string
    {
        return $this->filled('bucket') ? $this->string('bucket')->toString() : null;
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
    }
}
