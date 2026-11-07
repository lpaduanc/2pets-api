<?php

namespace Tests\Feature\Import;

use App\Models\DataImport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Importação por XLSX (PLANO, Fase 4): a planilha gerada aqui, com células tipadas como o Excel
 * grava (data serial, número com máscara de zeros, decimal), dá as MESMAS linhas, o mesmo
 * mapeamento e o mesmo resultado da validação que o CSV equivalente — inclusive o `row_number`
 * dos erros. `.xls` é recusado; o modelo sai em CSV (padrão) e em XLSX com o mesmo cabeçalho.
 */
class XlsxImportTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->vet = User::factory()->professional()->create();
        // Ordem de dependência da importação (pets pedem clientes; vacinas pedem pets).
        // O tutor tem o CPF das planilhas e o pet "Rex" (as vacinas apontam para ele).
        $tutor = User::factory()->tutor()->create(['cpf' => '01234567890']);
        \App\Models\ProfessionalClient::create(['professional_id' => $this->vet->id, 'client_id' => $tutor->id]);
        \App\Models\Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Rex']);
    }

    /** @return array<string, array{0: string, 1: string, 2: list<list<mixed>>}> */
    public static function entities(): array
    {
        $birth = ['date', '1990-03-05'];

        return [
            // CSV com `,` (sem decimal) ou `;` (com decimal em vírgula, como o Excel pt-BR salva).
            // CPF e telefone com zero à esquerda (máscara de zeros); data de nascimento serial; linha 2 sem nome (erro).
            'clientes' => ['clients', "nome,cpf,email,telefone,data_nascimento\nJoão Silva,01234567890,joao@example.com,(11) 98765-4321,05/03/1990\n,52998224725,sem-nome@example.com,,\n\nMaria Souza,52998224725,maria@example.com,0800123456,01/12/1985\n", [
                ['nome', 'cpf', 'email', 'telefone', 'data_nascimento'],
                ['João Silva', ['mask', 1234567890, '00000000000'], 'joao@example.com', ['mask', 11987654321, '(00) 00000-0000'], $birth],
                [null, ['mask', 52998224725, '00000000000'], 'sem-nome@example.com', null, null],
                [null, null, null, null, null],
                ['Maria Souza', ['text', '52998224725'], 'maria@example.com', ['mask', 800123456, '0000000000'], ['date', '1985-12-01']],
            ]],
            'pets' => ['pets', "nome;especie;raca;sexo;peso;data_nascimento;castrado;cpf_tutor\nRex;cachorro;SRD;macho;12,5;05/03/2020;sim;01234567890\nMia;gato;;femea;3,2;;não;01234567890\n", [
                ['nome', 'especie', 'raca', 'sexo', 'peso', 'data_nascimento', 'castrado', 'cpf_tutor'],
                ['Rex', 'cachorro', 'SRD', 'macho', 12.5, ['date', '2020-03-05'], true, ['mask', 1234567890, '00000000000']],
                ['Mia', 'gato', null, 'femea', 3.2, null, false, ['mask', 1234567890, '00000000000']],
            ]],
            'produtos' => ['products', "nome;codigo;codigo_de_barras;ncm;unidade;preco;custo;estoque_inicial\nRação 10kg;0042;07891234567895;23091000;UN;189,9;120,5;12\nAreia;0043;;25081000;KG;12,5;8;0,5\n", [
                ['nome', 'codigo', 'codigo_de_barras', 'ncm', 'unidade', 'preco', 'custo', 'estoque_inicial'],
                ['Ração 10kg', ['mask', 42, '0000'], ['mask', 7891234567895, '00000000000000'], 23091000, 'UN', 189.9, 120.5, 12],
                ['Areia', ['text', '0043'], null, 25081000, 'KG', 12.5, 8, 0.5],
            ]],
            'vacinas' => ['vaccinations', "nome_do_pet,cpf_tutor,vacina,lote,data_aplicacao,validade,proxima_dose\nRex,01234567890,V10,A123,01/10/2026,01/10/2027,01/10/2027\n", [
                ['nome_do_pet', 'cpf_tutor', 'vacina', 'lote', 'data_aplicacao', 'validade', 'proxima_dose'],
                ['Rex', ['mask', 1234567890, '00000000000'], 'V10', 'A123', ['date', '2026-10-01'], ['date', '2027-10-01'], ['date', '2027-10-01']],
            ]],
        ];
    }

    /** @param  list<list<mixed>>  $cells */
    #[DataProvider('entities')]
    public function test_xlsx_gives_the_same_rows_mapping_and_validation_as_the_csv(string $entity, string $csv, array $cells): void
    {
        $fromCsv = $this->upload($entity, UploadedFile::fake()->createWithContent("{$entity}.csv", $csv))->assertCreated();
        $fromXlsx = $this->upload($entity, $this->xlsx("{$entity}.xlsx", $cells))->assertCreated();

        $this->assertSame($fromCsv->json('data.raw_headers'), $fromXlsx->json('data.raw_headers'));
        $this->assertSame($fromCsv->json('data.column_mapping'), $fromXlsx->json('data.column_mapping'));
        $this->assertSame($this->rows($fromCsv->json('data.id')), $this->rows($fromXlsx->json('data.id')));
        $this->assertSame('xlsx', DataImport::findOrFail($fromXlsx->json('data.id'))->source);

        $csvValidation = $this->actingAs($this->vet)->postJson("/api/data-imports/{$fromCsv->json('data.id')}/validate")->assertOk()->json();
        $xlsxValidation = $this->actingAs($this->vet)->postJson("/api/data-imports/{$fromXlsx->json('data.id')}/validate")->assertOk()->json();
        $this->assertSame($csvValidation, $xlsxValidation);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function executions(): array
    {
        $out = [];

        foreach (array_keys(self::entities()) as $key) {
            $out["{$key}-csv"] = [$key, 'csv'];
            $out["{$key}-xlsx"] = [$key, 'xlsx'];
        }

        return $out;
    }

    /** Ponta a ponta: upload → validação → execução (fila síncrona na suíte) → registros criados. */
    #[DataProvider('executions')]
    public function test_import_runs_end_to_end_in_csv_and_xlsx(string $key, string $format): void
    {
        [$entity, $csv, $cells] = self::entities()[$key];
        $file = $format === 'csv' ? UploadedFile::fake()->createWithContent("{$key}.csv", $csv) : $this->xlsx("{$key}.xlsx", $cells);
        $id = $this->upload($entity, $file)->assertCreated()->json('data.id');
        $validation = $this->actingAs($this->vet)->postJson("/api/data-imports/{$id}/validate")->assertOk()->json();
        $this->assertGreaterThan(0, $validation['valid_rows'], json_encode($validation));

        $this->actingAs($this->vet)->postJson("/api/data-imports/{$id}/execute", ['duplicate_strategy' => 'skip'])->assertStatus(202);

        $import = DataImport::findOrFail($id);
        $this->assertSame(\App\Enums\Import\ImportStatus::COMPLETED, $import->status);
        $this->assertImported($entity);
    }

    private function assertImported(string $entity): void
    {
        match ($entity) {
            // João tem o CPF do tutor que já existe (duplicado, pulado); Maria é nova.
            'clients' => $this->assertSame(['52998224725', '0800123456', '1985-12-01'], (fn (User $u): array => [$u->cpf, $u->phone, $u->birth_date?->toDateString()])(User::where('email', 'maria@example.com')->sole())),
            'pets' => $this->assertSame(['3.20', false], (fn ($p): array => [number_format((float) $p->weight, 2, '.', ''), (bool) $p->neutered])(\App\Models\Pet::where('name', 'Mia')->sole())),
            'products' => $this->assertSame(['0042', '07891234567895', '189.90', '12.0000'], (fn ($p): array => [$p->code, $p->gtin, number_format((float) $p->price, 2, '.', ''), (string) $p->stock_quantity])(\App\Models\Product::where('name', 'Ração 10kg')->sole())),
            'vaccinations' => $this->assertSame(1, \App\Models\Vaccination::whereHas('pet', fn ($q) => $q->where('name', 'Rex'))->count()),
        };
    }

    public function test_leading_zeros_serial_dates_and_error_row_numbers(): void
    {
        $upload = $this->upload('clients', $this->xlsx('clientes.xlsx', self::entities()['clientes'][2]))->assertCreated();
        $rows = $this->rows($upload->json('data.id'));

        // A linha totalmente vazia some; a numeração é a mesma do CSV.
        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertSame(['01234567890', '(11) 98765-4321', '05/03/1990'], [$rows[1]['cpf'], $rows[1]['telefone'], $rows[1]['data_nascimento']]);
        $this->assertSame(['0800123456', '01/12/1985'], [$rows[3]['telefone'], $rows[3]['data_nascimento']]);

        $validation = $this->actingAs($this->vet)->postJson("/api/data-imports/{$upload->json('data.id')}/validate")->assertOk();
        $this->assertSame([2], collect($validation->json('sample_errors'))->pluck('row_number')->all());

        $product = $this->rows($this->upload('products', $this->xlsx('produtos.xlsx', self::entities()['produtos'][2]))->json('data.id'));
        $this->assertSame(['0042', '07891234567895', '189,9', '0,5'], [$product[1]['codigo'], $product[1]['codigo_de_barras'], $product[1]['preco'], $product[2]['estoque_inicial']]);
    }

    public function test_xls_is_refused(): void
    {
        $this->upload('clients', UploadedFile::fake()->create('clientes.xls', 10, 'application/vnd.ms-excel'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_template_in_csv_by_default_and_in_xlsx_on_request(): void
    {
        $csv = $this->actingAs($this->vet)->get('/api/data-imports/templates/pets')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $headers = explode(',', trim($csv->streamedContent()));

        $xlsx = $this->actingAs($this->vet)->get('/api/data-imports/templates/pets?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        $this->assertSame($headers, $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0]);
        $this->assertSame('@', $sheet->getStyle('J2')->getNumberFormat()->getFormatCode());

        $this->actingAs($this->vet)->getJson('/api/data-imports/templates/pets?format=pdf')->assertUnprocessable();
    }

    private function upload(string $entity, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->vet)->post('/api/data-imports', ['entity' => $entity, 'file' => $file], ['Accept' => 'application/json']);
    }

    /** @return array<int, array<string, string>> por `row_number` */
    private function rows(int $importId): array
    {
        return DataImport::findOrFail($importId)->rows()->orderBy('row_number')->get()->mapWithKeys(fn ($row): array => [$row->row_number => $row->raw])->all();
    }

    /**
     * Célula: escalar (texto, número ou booleano), `['date', 'Y-m-d']` (serial com formato de
     * data), `['mask', número, máscara]` (zero à esquerda pela máscara) ou `['text', '...']`.
     *
     * @param  list<list<mixed>>  $cells
     */
    private function xlsx(string $name, array $cells): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($cells as $r => $row) {
            foreach ($row as $c => $value) {
                $coordinate = [$c + 1, $r + 1];
                match (true) {
                    is_array($value) && $value[0] === 'date' => $sheet->setCellValue($coordinate, ExcelDate::stringToExcel($value[1]))->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                    is_array($value) && $value[0] === 'mask' => $sheet->setCellValue($coordinate, $value[1])->getStyle($coordinate)->getNumberFormat()->setFormatCode($value[2]),
                    is_array($value) && $value[0] === 'text' => $sheet->setCellValueExplicit($coordinate, $value[1], DataType::TYPE_STRING),
                    $value === null => null,
                    default => $sheet->setCellValue($coordinate, $value),
                };
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
