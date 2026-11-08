<?php

/*
 * Fiscal — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §4. Sem provedor terceiro: a
 * transmissão será própria (driver futuro do `FiscalProviderGateway`); estes números regem a
 * retentativa e a contingência independentemente do driver.
 */
return [
    'contingency' => [
        // Prazo para transmitir a NFC-e emitida offline. A regra usual é 24 h, mas o prazo legal
        // varia por UF — CONFIRMAR com o contador antes de produção. Passou disso, o documento
        // aparece como atrasado em `reports/fiscal-pending`.
        'max_hours' => (int) env('FISCAL_CONTINGENCY_MAX_HOURS', 24),
    ],

    'retry' => [
        // Espera (minutos) antes de cada nova tentativa quando o provedor está fora do ar; da
        // última em diante repete o último valor. Nunca bloqueia a venda.
        'backoff_minutes' => [1, 5, 15, 60, 240],
    ],
];
