<?php

namespace App\Services\Import;

use App\Enums\Import\ImportEntity;
use App\Exceptions\Import\ImportEntityNotSupportedException;

/**
 * Modelo de planilha por entidade (item 26 do backlog gap-simplesvet) — cabeçalho que o
 * `ColumnMapper` já reconhece por alias, para o usuário baixar e preencher sem adivinhar coluna.
 */
final class ImportTemplateProvider
{
    /**
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'clients' => ['nome', 'cpf', 'email', 'telefone', 'endereco', 'data_nascimento'],
        'pets' => [
            'nome', 'especie', 'raca', 'pelagem', 'sexo', 'peso', 'data_nascimento',
            'castrado', 'microchip', 'cpf_tutor', 'email_tutor', 'telefone_tutor',
        ],
        'products' => [
            'nome', 'codigo', 'sku', 'codigo_de_barras', 'ncm', 'unidade', 'marca',
            'grupo', 'preco', 'custo', 'markup', 'estoque_inicial',
        ],
        'vaccinations' => [
            'nome_do_pet', 'cpf_tutor', 'email_tutor', 'telefone_tutor', 'vacina',
            'fabricante', 'lote', 'data_aplicacao', 'validade', 'proxima_dose', 'dose',
        ],
    ];

    public function toCsv(ImportEntity $entity): string
    {
        return implode(',', $this->headersFor($entity));
    }

    /**
     * @return list<string>
     */
    private function headersFor(ImportEntity $entity): array
    {
        return self::HEADERS[$entity->value] ?? throw new ImportEntityNotSupportedException($entity);
    }
}
