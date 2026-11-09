<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalGatewayResolver;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEnvironment;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Parte de numeração/ambiente/contingência do `fiscal-settings` — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §6. Ambiente e série vão na organização; o próximo número, na
 * sequência do ambiente/série JÁ gravados (por isso a ordem dentro da transação).
 */
final class FiscalNumberingSettings
{
    private const SERIES_COLUMNS = [FiscalDocumentKind::NFE->value => 'nfe_series', FiscalDocumentKind::NFCE->value => 'nfce_series', FiscalDocumentKind::NFSE->value => 'nfse_series'];

    public function __construct(
        private readonly FiscalNumberSequencer $sequencer,
        private readonly FiscalGatewayResolver $gateways,
    ) {}

    /** @param  array<string, mixed>  $data  payload validado de `UpdateFiscalSettingsRequest` */
    public function apply(Organization $organization, array $data): void
    {
        DB::transaction(function () use ($organization, $data): void {
            $series = (array) ($data['fiscal_series'] ?? []);
            $organization->update(array_diff_key($data, ['fiscal_series' => true]) + $this->seriesColumns($series));
            $owner = FiscalOwner::forOrganization($organization->id);

            foreach ($series as $kind => $settings) {
                $this->configureNextNumber($owner, FiscalDocumentKind::from($kind), $settings);
            }
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(Organization $organization): array
    {
        $owner = FiscalOwner::forOrganization($organization->id);
        // Driver da NF-e deste dono (o da NFC-e pode diferir: depende do CSC — ver `transmission`).
        $gateway = $this->gateways->forOwner($owner, FiscalDocumentKind::NFE);

        return [
            'fiscal_environment' => ($organization->fiscal_environment ?? FiscalEnvironment::HOMOLOGATION)->value,
            'fiscal_series' => array_map(fn (string $kind): array => $this->sequence($owner, FiscalDocumentKind::from($kind)), array_combine(array_keys(self::SERIES_COLUMNS), array_keys(self::SERIES_COLUMNS))),
            'contingency' => [
                'active' => $organization->isInFiscalContingency(),
                'started_at' => $organization->fiscal_contingency_started_at?->toIso8601String(),
                'reason' => $organization->fiscal_contingency_reason,
            ],
            'provider' => ['name' => $gateway->name(), 'capabilities' => $gateway->capabilities()->toArray()],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $series
     * @return array<string, string>
     */
    private function seriesColumns(array $series): array
    {
        $columns = [];

        foreach ($series as $kind => $settings) {
            if (isset($settings['series'])) {
                $columns[self::SERIES_COLUMNS[$kind]] = (string) $settings['series'];
            }
        }

        return $columns;
    }

    /** @param  array<string, mixed>  $settings */
    private function configureNextNumber(FiscalOwner $owner, FiscalDocumentKind $kind, array $settings): void
    {
        if (isset($settings['next_number'])) {
            $this->sequencer->configure($owner, $kind, (int) $settings['next_number']);
        }
    }

    /** @return array{series: string, next_number: int, start_number: int} */
    private function sequence(FiscalOwner $owner, FiscalDocumentKind $kind): array
    {
        $sequence = $this->sequencer->current($owner, $kind);

        return ['series' => $sequence->series, 'next_number' => $sequence->next_number, 'start_number' => $sequence->start_number];
    }
}
