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
        // PL_009_V4 também NÃO aceita CNPJ alfanumérico (NT 2026.004): emitente com letra fica com
        // `cnpj_alphanumeric_schema` na prontidão e a emissão é recusada localmente até a troca.
        'schema' => env('FISCAL_SEFAZ_SCHEMA', 'PL_009_V4'),
        'timeout_seconds' => (int) env('FISCAL_SEFAZ_TIMEOUT', 20),
        // `verProc`/`verAplic`: identificação do emissor no XML.
        'app_version' => '2pets 1.0',
        // Pasta PRIVADA onde o cURL do sped-common grava a chave privada temporária (cifrada com
        // senha aleatória) durante o envio — nunca o /tmp compartilhado.
        'temporary_path' => storage_path('app/private/fiscal-tmp'),
        // Verificação do servidor TLS (spec §3.5): o bundle usado é a SOMA do trust store do sistema
        // (UFs com CA pública: AN/SVAN, MG, PA…) com as ACs ICP-Brasil versionadas (SVRS, SP, PR,
        // BA…). Qualquer um ausente/ilegível = nada é enviado e a prontidão mostra `sefaz_ca_bundle`.
        // `system_ca_bundle` vazio de propósito = só ICP-Brasil (as UFs com CA pública falham).
        'ca_bundle' => env('FISCAL_SEFAZ_CA_BUNDLE', resource_path('fiscal/ca/icp-brasil.pem')),
        'system_ca_bundle' => env('FISCAL_SEFAZ_SYSTEM_CA_BUNDLE', openssl_get_cert_locations()['default_cert_file'] ?? '/etc/ssl/certs/ca-certificates.crt'),
        // Pasta privada do bundle combinado (nome = sha256 do conteúdo; refeito quando muda).
        'trust_path' => storage_path('app/private/fiscal-ca'),
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

    /*
     * NFS-e pelo Sistema Nacional (Sefin Nacional / Emissor Público Nacional) — spec
     * docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md. Desligada por padrão e
     * independente da SEFAZ: ligar NF-e não liga NFS-e.
     */
    'nfse' => [
        'enabled' => (bool) env('FISCAL_NFSE_ENABLED', false),
        // Hosts confirmados pelo certificado TLS; o caminho `/SefinNacional` NÃO consta do manual
        // oficial (o Swagger exige mTLS) — confirmar na produção restrita (spec §1.1).
        'base_urls' => [
            'production' => env('FISCAL_NFSE_URL_PRODUCTION', 'https://sefin.nfse.gov.br/SefinNacional'),
            'homologation' => env('FISCAL_NFSE_URL_HOMOLOGATION', 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional'),
        ],
        // Leiaute da DPS por ambiente (`App\Enums\NfseLayout`): a produção restrita já roda o
        // leiaute das NTs 005/009 (finNFSe obrigatório, cServ reordenado), a produção o de
        // 2026-02-09. Cada um é validado no seu XSD oficial (resources/fiscal/nfse/schemas).
        'layouts' => [
            'production' => env('FISCAL_NFSE_LAYOUT_PRODUCTION', 'v1.01-20260209'),
            'homologation' => env('FISCAL_NFSE_LAYOUT_HOMOLOGATION', 'v1.01-rtc-20261020'),
        ],
        // sha1 | sha256. O Anexo I não fixa o algoritmo; o único documento oficial que fala dele (guia
        // NFS-e Via API v1.02) pede RSA-SHA256, então o padrão segue o oficial. Implementações de
        // terceiros usam SHA-1 — se a produção restrita recusar, troca por env (spec §1.1).
        'signature_algorithm' => env('FISCAL_NFSE_SIGNATURE_ALGORITHM', 'sha256'),
        // Bundle de CAs para VERIFICAR o servidor. Vazio = trust store do sistema (os hosts usam
        // cadeia pública: GlobalSign Root R46 e ISRG Root X1). Configurado e ausente = nada é
        // enviado (falha fechada) e a prontidão mostra `nfse_ca_bundle`.
        'ca_bundle' => env('FISCAL_NFSE_CA_BUNDLE'),
        'timeout_seconds' => (int) env('FISCAL_NFSE_TIMEOUT', 30),
        'connect_timeout_seconds' => 10,
        // dhEmi recua esse tanto: a Sefin recusa DPS com emissão depois do processamento (E0008)
        // e nosso relógio pode estar adiantado.
        'clock_skew_seconds' => 60,
        'app_version' => '2pets 1.0',
        // Pasta PRIVADA dos PEM temporários do mTLS (chave cifrada com senha de uso único).
        'temporary_path' => storage_path('app/private/fiscal-tmp'),
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
