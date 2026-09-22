<?php

namespace App\Support\Catalog;

use App\Enums\HolidayScope;
use App\Enums\ServiceCategory;
use App\Models\AppointmentType;
use App\Models\Coat;
use App\Models\Holiday;
use App\Models\HospitalizationBox;
use App\Models\Occupation;
use Illuminate\Validation\Rule;

/**
 * Único ponto de extensão do `CatalogController` genérico (item 23 do backlog gap-simplesvet)
 * — adicionar um catálogo novo é uma linha em `TYPES` e, se ele tiver campo próprio além de
 * `name`/`active`, um `case` em `extraRulesFor()`.
 *
 * **`customer-sources`/`loss-reasons` foram REMOVIDOS** (consolidação pedida pelo coordenador
 * — duplicavam `client-origins`/`churn-reasons` do item 18, que já existiam com semântica mais
 * rica: catálogo global seedado + auto-resolução de origem + Policy dedicada, nenhuma delas
 * replicável pelo CRUD genérico deste controller). Origem/motivo de perda de cliente continuam
 * em `GET/POST/PUT/DELETE client-origins|churn-reasons`
 * (`App\Http\Controllers\Api\Crm\ClientOriginController`/`ChurnReasonController`), não aqui —
 * ver `docs/gap-simplesvet/contratos/18-contrato-api.md` e `23-contrato-api.md`.
 */
final class CatalogTypeRegistry
{
    /**
     * @var array<string, class-string>
     */
    private const TYPES = [
        'coats' => Coat::class,
        'occupations' => Occupation::class,
        'holidays' => Holiday::class,
        'hospitalization-boxes' => HospitalizationBox::class,
        'appointment-types' => AppointmentType::class,
    ];

    /**
     * @return class-string
     */
    public static function modelClassFor(string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            abort(404, "Catálogo \"{$type}\" não existe.");
        }

        return self::TYPES[$type];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function extraRulesFor(string $type, bool $isCreate): array
    {
        if (! isset(self::TYPES[$type])) {
            abort(404, "Catálogo \"{$type}\" não existe.");
        }

        $required = $isCreate ? 'required' : 'sometimes';

        return match ($type) {
            // `scope`/`uf`/`city_ibge_code` vieram da consolidação com `company_holidays`
            // (item 03, financeiro — `DueDateService`). `national` não é aceito aqui: o
            // feriado nacional só nasce pelo `HolidaySeeder`, nunca pelo cadastro da clínica.
            'holidays' => [
                'date' => [$required, 'date'],
                'recurring_annually' => ['sometimes', 'boolean'],
                'scope' => ['sometimes', Rule::enum(HolidayScope::class)->except(HolidayScope::NATIONAL)],
                'uf' => ['sometimes', 'nullable', 'string', 'size:2'],
                'city_ibge_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            ],
            'hospitalization-boxes' => [
                'capacity' => ['sometimes', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            // Regra de negócio 1 da spec 14: `category` é obrigatório e é sempre um valor
            // de `ServiceCategory` — nunca um vocabulário livre paralelo.
            'appointment-types' => [
                'category' => [$required, Rule::in(ServiceCategory::values())],
                'default_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
                'color' => ['nullable', 'string', 'max:20'],
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }
}
