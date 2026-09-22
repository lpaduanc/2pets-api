{{-- Pedido de exame em PDF — mesmo esqueleto visual de pdfs/prescription.blade.php. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Pedido de exame — {{ $examRequest->pet?->name }}</title>
    <style>
        @page { margin: 25mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #d8dee9; padding-bottom: 3px; }
        .header { border-bottom: 2px solid #5D87FF; padding-bottom: 8px; margin-bottom: 14px; }
        .muted { color: #6b7684; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        .data td { padding: 2px 0; vertical-align: top; }
        .data td.label { width: 130px; color: #6b7684; }
        ul { margin: 0; padding-left: 18px; }
        .signature { margin-top: 48px; text-align: center; }
        .signature .line { border-top: 1px solid #1f2933; width: 260px; margin: 0 auto 4px; }
    </style>
</head>
<body>
<div class="header">
    <h1>Pedido de Exame</h1>
    <div class="muted">Emitido em {{ $examRequest->created_at?->format('d/m/Y') }}</div>
</div>

<h2>Paciente</h2>
<table class="data">
    <tr><td class="label">Animal</td><td>{{ $examRequest->pet?->name ?? '—' }}</td></tr>
    <tr><td class="label">Espécie / Raça</td><td>{{ $examRequest->pet?->species ?? '—' }}{{ $examRequest->pet?->breed ? ' / '.$examRequest->pet->breed : '' }}</td></tr>
    <tr><td class="label">Tutor</td><td>{{ $examRequest->pet?->user?->name ?? '—' }}</td></tr>
</table>

<h2>Exames solicitados</h2>
@if ($examRequest->examTypes->isEmpty())
    <p class="muted">Nenhum tipo de exame do catálogo associado a este pedido.</p>
@else
    <ul>
        @foreach ($examRequest->examTypes as $examType)
            <li>{{ $examType->name }} ({{ $examType->category?->label() }})</li>
        @endforeach
    </ul>
@endif

@if ($examRequest->clinical_notes)
    <h2>Observações clínicas</h2>
    <p>{{ $examRequest->clinical_notes }}</p>
@endif

<div class="signature">
    <div class="line"></div>
    <div>{{ $examRequest->requestedBy?->name ?? '—' }}</div>
    @if ($examRequest->requestedBy?->professional?->crmv)
        <div class="muted">
            CRMV {{ $examRequest->requestedBy->professional->crmv }}{{ $examRequest->requestedBy->professional->crmv_state ? '/'.$examRequest->requestedBy->professional->crmv_state : '' }}
        </div>
    @endif
</div>
</body>
</html>
