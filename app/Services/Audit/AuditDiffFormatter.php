<?php

namespace App\Services\Audit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Traduz `properties.old`/`new` do activity log (spatie/laravel-activitylog) em um diff
 * legível pt-BR — item 22 do backlog gap-simplesvet. Antes desta classe, `PetAuditController`
 * devolvia o JSON cru dos atributos (gap registrado no critério de aceite "Peso: 12,5 kg →
 * 13,2 kg").
 *
 * Defesa em profundidade: mesmo que um model logue um campo sensível por engano, este
 * formatter nunca o exibe (ver SELF::SENSITIVE_FIELDS). A blindagem primária continua sendo o
 * `logOnly()` explícito de cada `getActivitylogOptions()`.
 */
class AuditDiffFormatter
{
    /**
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'name' => 'Nome',
        'status' => 'Status',
        'notes' => 'Observações',
        'weight' => 'Peso',
        'weight_kg' => 'Peso',
        'price' => 'Preço',
        'total' => 'Total',
        'amount' => 'Valor',
        'amount_cents' => 'Valor',
        'active' => 'Ativo',
        'is_active' => 'Ativo',
        'email' => 'E-mail',
        'phone' => 'Telefone',
        'role' => 'Cargo',
        'organization_id' => 'Organização',
        'user_id' => 'Usuário',
        'cnpj' => 'CNPJ',
        'business_name' => 'Razão social',
    ];

    /**
     * Nunca exibido, mesmo que apareça em `properties` por engano de um model novo.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'password', 'token', 'remember_token', 'api_key', 'api_token', 'secret',
        'access_token', 'refresh_token', 'certificate', 'document_content',
        'crmv_document', 'cpf_document', 'card_number', 'cvv',
    ];

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return list<array{field: string, label: string, old: string|null, new: string|null, text: string}>
     */
    public function diff(?array $old, ?array $new): array
    {
        $old ??= [];
        $new ??= [];
        $fields = array_unique([...array_keys($old), ...array_keys($new)]);

        $rows = [];
        foreach ($fields as $field) {
            if ($this->isSensitive($field) || ($old[$field] ?? null) === ($new[$field] ?? null)) {
                continue;
            }

            $rows[] = $this->formatRow($field, $old[$field] ?? null, $new[$field] ?? null);
        }

        return $rows;
    }

    private function isSensitive(string $field): bool
    {
        foreach (self::SENSITIVE_FIELDS as $sensitive) {
            if (str_contains($field, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{field: string, label: string, old: string|null, new: string|null, text: string}
     */
    private function formatRow(string $field, mixed $oldValue, mixed $newValue): array
    {
        $label = self::FIELD_LABELS[$field] ?? Str::headline(str_replace('_', ' ', $field));
        $oldFormatted = $this->formatValue($field, $oldValue);
        $newFormatted = $this->formatValue($field, $newValue);

        return [
            'field' => $field,
            'label' => $label,
            'old' => $oldFormatted,
            'new' => $newFormatted,
            'text' => "{$label}: ".($oldFormatted ?? '—').' → '.($newFormatted ?? '—'),
        ];
    }

    private function formatValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if (str_contains($field, 'weight')) {
            return number_format((float) $value, 1, ',', '.').' kg';
        }

        if (str_ends_with($field, '_cents')) {
            return 'R$ '.number_format(((float) $value) / 100, 2, ',', '.');
        }

        if (str_contains($field, 'price') || str_contains($field, 'amount') || str_contains($field, 'total')) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        }

        if (str_ends_with($field, '_at') || str_ends_with($field, '_date')) {
            return $this->formatDate((string) $value);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private function formatDate(string $value): string
    {
        try {
            return Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }
}
