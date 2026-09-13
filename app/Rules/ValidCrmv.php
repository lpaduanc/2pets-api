<?php

namespace App\Rules;

use App\Services\CrmvValidationService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o formato do CRMV (ex.: `CRMV/SP 12345`) contra a UF informada em outro campo do
 * mesmo payload — por isso implementa `DataAwareRule`: sem os dados irmãos, não há UF para
 * comparar contra o prefixo do CRMV.
 *
 * `$stateField` é configurável porque o projeto valida CRMV em dois contextos com nomes de
 * campo diferentes: o do próprio veterinário (`crmv_state`) e o do responsável técnico da
 * clínica/laboratório (`technical_responsible_crmv_state`).
 */
final class ValidCrmv implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly CrmvValidationService $crmvValidationService,
        private readonly string $stateField = 'crmv_state',
    ) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $state = (string) ($this->data[$this->stateField] ?? '');

        // UF ausente é responsabilidade da regra `required`/`size:2` do próprio campo de
        // estado — não duplicamos o erro aqui.
        if ($state === '') {
            return;
        }

        if (! $this->crmvValidationService->validateFormat((string) $value, $state)) {
            $fail('CRMV inválido');
        }
    }
}
