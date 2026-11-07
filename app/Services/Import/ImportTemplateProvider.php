<?php

namespace App\Services\Import;

use App\Enums\Import\ImportEntity;
use App\Exceptions\Import\ImportEntityNotSupportedException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
     * Mesmo cabeçalho do CSV, em XLSX (PLANO, Fase 4). As colunas saem com formato TEXTO para o
     * Excel não comer o zero à esquerda de CPF, CEP e telefone nem converter código em número.
     */
    public function toXlsx(ImportEntity $entity): string
    {
        $headers = $this->headersFor($entity);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('modelo');
        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1000")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        foreach (range(1, count($headers)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function headersFor(ImportEntity $entity): array
    {
        return self::HEADERS[$entity->value] ?? throw new ImportEntityNotSupportedException($entity);
    }
}
