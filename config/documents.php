<?php

/**
 * Document storage policy — drives where sensitive uploads land and how long they live.
 *
 * CRMV, RG, CPF, diploma: dados pessoais sensíveis (LGPD Art. 5, II — documento de identificação).
 *   - Must live on an S3 bucket with server-side encryption (AES-256 or KMS).
 *   - Private by default — only accessed via temporary signed URLs.
 *   - Retention: kept while the user is active + 5 anos após encerramento (defesa em processo).
 *   - Delete request (Art. 18, VI): move to legal-hold bucket; wipe after retention.
 */
return [
    'disk' => env('DOCUMENTS_DISK', 'documents'),

    // TTL for temporary signed URLs (seconds). Short window reduces the chance of URL leakage.
    'signed_url_ttl' => (int) env('DOCUMENTS_SIGNED_URL_TTL', 300),

    // Days to keep a document after the user is deleted (Art. 16 — conservation for legal purposes).
    'retention_days_after_deletion' => (int) env('DOCUMENTS_RETENTION_DAYS', 1825), // 5 years

    'mime_whitelist' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ],

    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 10240), // 10 MB
];
