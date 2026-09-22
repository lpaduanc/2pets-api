{{--
    Documento gerado (atestado/termo/declaração) em PDF — corpo já congelado
    (`generated_documents.body_html`), mesmo esqueleto visual dos demais PDFs clínicos.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $document->documentTemplate?->name }} — {{ $document->pet?->name }}</title>
    <style>
        @page { margin: 25mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .header { border-bottom: 2px solid #5D87FF; padding-bottom: 8px; margin-bottom: 14px; }
        .muted { color: #6b7684; font-size: 10px; }
        .body { margin: 18px 0; }
        .verification { margin-top: 24px; font-size: 9px; color: #6b7684; border-top: 1px dashed #d8dee9; padding-top: 6px; }
        .signature { margin-top: 48px; text-align: center; }
        .signature img { max-height: 60px; }
        .signature .line { border-top: 1px solid #1f2933; width: 260px; margin: 4px auto; }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ $document->documentTemplate?->name }}</h1>
    <div class="muted">Emitido em {{ $document->issued_at?->format('d/m/Y H:i') }}</div>
</div>

<div class="body">
    {!! $document->body_html !!}
</div>

<div class="signature">
    <div class="line"></div>
    <div>{{ $document->issuedBy?->name ?? '—' }}</div>
    @if ($document->issuedBy?->professional?->crmv)
        <div class="muted">
            CRMV {{ $document->issuedBy->professional->crmv }}{{ $document->issuedBy->professional->crmv_state ? '/'.$document->issuedBy->professional->crmv_state : '' }}
        </div>
    @endif
</div>

@if ($document->verification_code)
    <div class="verification">
        Assinatura eletrônica simples — nome, CRMV, data/hora e hash do conteúdo (não é
        assinatura digital com certificado ICP-Brasil). Verifique a autenticidade em
        {{ config('app.frontend_url', config('app.url')) }}/documentos/verificar/{{ $document->verification_code }}
        — código {{ $document->verification_code }}.
    </div>
@endif
</body>
</html>
