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

    /*
     * Transmissão própria à SEFAZ (NF-e 55 / NFC-e 65) — spec docs/gap-petflow/specs/
     * fase4-transmissao-propria-nfe-nfce.md. Desligada por padrão: com ela desligada toda
     * organização segue em "emissão simulada" (driver log), mesmo com certificado cadastrado.
     */
    'sefaz' => [
        'enabled' => (bool) env('FISCAL_SEFAZ_ENABLED', false),
        // Pacote de schemas do sped-nfe. PL_009_V4 = layout 4.00 sem os grupos IBS/CBS da reforma
        // (NT 2025.002); trocar para PL_010_V1.30 quando a reforma passar a exigir os grupos.
        'schema' => env('FISCAL_SEFAZ_SCHEMA', 'PL_009_V4'),
        'timeout_seconds' => (int) env('FISCAL_SEFAZ_TIMEOUT', 20),
        // `verProc`/`verAplic`: identificação do emissor no XML.
        'app_version' => '2pets 1.0',
        // Pasta PRIVADA onde o cURL do sped-common grava a chave privada temporária (cifrada com
        // senha aleatória) durante o envio — nunca o /tmp compartilhado.
        'temporary_path' => storage_path('app/private/fiscal-tmp'),
        // Disco dos XMLs autorizados e DANFEs: privado, guardados por 5 anos (CTN art. 173/174).
        'disk' => env('FISCAL_DOCUMENTS_DISK', 'local'),
        // Responsável técnico (infRespTec) — obrigatório em várias UFs; vazio = grupo omitido.
        'technical_responsible' => [
            'cnpj' => env('FISCAL_RESP_TEC_CNPJ'),
            'contact' => env('FISCAL_RESP_TEC_CONTACT'),
            'email' => env('FISCAL_RESP_TEC_EMAIL'),
            'phone' => env('FISCAL_RESP_TEC_PHONE'),
        ],
    ],

    'certificate' => [
        'max_kilobytes' => 64,
        // A partir de quantos dias para vencer a tela avisa para renovar o A1.
        'warning_days' => 30,
    ],

    // Chave própria dos segredos fiscais (`FiscalSecretCipher`); vazia cai no APP_KEY (só dev).
    'secrets' => [
        'key' => env('FISCAL_SECRETS_KEY'),
    ],
];
