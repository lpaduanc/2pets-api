<?php

namespace App\Services\Import;

use App\Enums\Import\ImportEntity;
use Illuminate\Support\Str;

/**
 * Sugestão de mapeamento coluna da planilha → campo do 2pets, por alias de cabeçalho
 * conhecido (item 26 do backlog gap-simplesvet). O usuário sempre confirma/ajusta antes de
 * validar — isto é só a sugestão inicial, nunca aplicado sem revisão.
 */
final class ColumnMapper
{
    /**
     * @var array<string, string>
     */
    private const CLIENT_FIELD_ALIASES = [
        'nome' => 'name',
        'nome completo' => 'name',
        'cliente' => 'name',
        'cpf' => 'cpf',
        'cpf/cnpj' => 'cpf',
        'documento' => 'cpf',
        'email' => 'email',
        'e-mail' => 'email',
        'telefone' => 'phone',
        'celular' => 'phone',
        'whatsapp' => 'phone',
        'endereco' => 'address',
        'endereço' => 'address',
        'data nascimento' => 'birth_date',
        'data de nascimento' => 'birth_date',
        'nascimento' => 'birth_date',
    ];

    /**
     * Tutor identificado pelas MESMAS três colunas que `ClientDuplicateDetector` já usa para
     * casar cliente — reaproveitado por `pets`/`vaccinations` para achar o dono do pet.
     *
     * @var array<string, string>
     */
    private const TUTOR_FIELD_ALIASES = [
        'tutor' => 'tutor_name',
        'cliente' => 'tutor_name',
        'cpf do tutor' => 'tutor_cpf',
        'cpf tutor' => 'tutor_cpf',
        'cpf cliente' => 'tutor_cpf',
        'email do tutor' => 'tutor_email',
        'email tutor' => 'tutor_email',
        'telefone do tutor' => 'tutor_phone',
        'telefone tutor' => 'tutor_phone',
    ];

    /**
     * @var array<string, string>
     */
    private const PET_FIELD_ALIASES = [
        'nome' => 'name',
        'nome do pet' => 'name',
        'pet' => 'name',
        'animal' => 'name',
        'especie' => 'species',
        'raca' => 'breed',
        'pelagem' => 'coat',
        'cor' => 'coat',
        'sexo' => 'gender',
        'genero' => 'gender',
        'peso' => 'weight',
        'data nascimento' => 'birth_date',
        'data de nascimento' => 'birth_date',
        'nascimento' => 'birth_date',
        'castrado' => 'neutered',
        'castrado(a)' => 'neutered',
        'microchip' => 'microchip_number',
    ];

    /**
     * @var array<string, string>
     */
    private const PRODUCT_FIELD_ALIASES = [
        'nome' => 'name',
        'produto' => 'name',
        'descricao' => 'description',
        'codigo' => 'code',
        'sku' => 'sku',
        'codigo de barras' => 'gtin',
        'gtin' => 'gtin',
        'ean' => 'gtin',
        'ncm' => 'ncm',
        'unidade' => 'unit_of_sale',
        'marca' => 'brand',
        'grupo' => 'product_group',
        'categoria' => 'product_group',
        'preco' => 'price',
        'preco de venda' => 'price',
        'valor' => 'price',
        'custo' => 'average_cost',
        'custo medio' => 'average_cost',
        'markup' => 'markup_percent',
        'estoque' => 'stock_quantity',
        'estoque inicial' => 'stock_quantity',
        'quantidade' => 'stock_quantity',
    ];

    /**
     * @var array<string, string>
     */
    private const VACCINATION_FIELD_ALIASES = [
        'vacina' => 'vaccine_name',
        'nome da vacina' => 'vaccine_name',
        'fabricante' => 'manufacturer',
        'laboratorio' => 'manufacturer',
        'lote' => 'batch_number',
        'data de aplicacao' => 'application_date',
        'data aplicacao' => 'application_date',
        'aplicacao' => 'application_date',
        'validade' => 'expiry_date',
        'data de validade' => 'expiry_date',
        'proxima dose' => 'next_dose_date',
        'proxima aplicacao' => 'next_dose_date',
        'dose' => 'dose_number',
        'numero da dose' => 'dose_number',
        'observacoes' => 'notes',
    ];

    /**
     * @param  list<string>  $headers
     * @return array<string, string> coluna da planilha => campo canônico sugerido
     */
    public function suggest(array $headers, ImportEntity $entity): array
    {
        $aliases = $this->aliasesFor($entity);
        $mapping = [];

        foreach ($headers as $header) {
            $field = $aliases[$this->normalize($header)] ?? null;
            if ($field !== null) {
                $mapping[$header] = $field;
            }
        }

        return $mapping;
    }

    /**
     * `_`/`-` viram espaço antes de comparar: planilha exportada por sistema legado varia
     * entre "Data de Nascimento", "data_nascimento" e "data-nascimento" para o mesmo campo, e
     * o CSV modelo deste próprio módulo (`ImportTemplateProvider`) usa a forma com `_`. Sem
     * isto, cada alias multi-palavra precisaria de uma entrada extra só para o separador.
     */
    private function normalize(string $header): string
    {
        return Str::of($header)->lower()->ascii()->replace(['_', '-'], ' ')->squish()->toString();
    }

    /**
     * @return array<string, string>
     */
    private function aliasesFor(ImportEntity $entity): array
    {
        return match ($entity) {
            ImportEntity::CLIENTS => self::CLIENT_FIELD_ALIASES,
            ImportEntity::PETS => [...self::PET_FIELD_ALIASES, ...self::TUTOR_FIELD_ALIASES],
            ImportEntity::PRODUCTS => self::PRODUCT_FIELD_ALIASES,
            ImportEntity::VACCINATIONS => $this->vaccinationAliases(),
            ImportEntity::SERVICES => [],
        };
    }

    /**
     * "Pet"/"animal" identificam o pet já cadastrado, nunca o nome dele — chave distinta de
     * `PET_FIELD_ALIASES['pet']` (que é o próprio cadastro do pet, não uma referência a ele).
     *
     * @return array<string, string>
     */
    private function vaccinationAliases(): array
    {
        return [
            ...self::VACCINATION_FIELD_ALIASES,
            ...self::TUTOR_FIELD_ALIASES,
            'pet' => 'pet_name',
            'nome do pet' => 'pet_name',
            'animal' => 'pet_name',
        ];
    }
}
