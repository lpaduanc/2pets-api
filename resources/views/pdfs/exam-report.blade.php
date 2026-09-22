{{--
    Laudo de exame em PDF (dompdf) — mesmo esqueleto visual de pdfs/prescription.blade.php,
    reaproveitado de propósito (identidade visual única entre os documentos clínicos do 2pets).
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Laudo — {{ $exam->pet?->name }}</title>
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
        .results th, .results td { border: 1px solid #d8dee9; padding: 6px; text-align: left; }
        .results th { background: #f2f5fb; font-size: 10px; text-transform: uppercase; }
        .signature { margin-top: 48px; text-align: center; }
        .signature .line { border-top: 1px solid #1f2933; width: 260px; margin: 0 auto 4px; }
    </style>
</head>
<body>
<div class="header">
    <h1>Laudo de Exame</h1>
    <div class="muted">{{ $exam->exam_name }} — {{ $exam->exam_date?->format('d/m/Y') }}</div>
</div>

<h2>Paciente</h2>
<table class="data">
    <tr><td class="label">Animal</td><td>{{ $exam->pet?->name ?? '—' }}</td></tr>
    <tr><td class="label">Espécie / Raça</td><td>{{ $exam->pet?->species ?? '—' }}{{ $exam->pet?->breed ? ' / '.$exam->pet->breed : '' }}</td></tr>
    <tr><td class="label">Tutor</td><td>{{ $exam->pet?->user?->name ?? '—' }}</td></tr>
</table>

@if ($exam->report_html)
    <h2>Laudo</h2>
    <div>{!! $exam->report_html !!}</div>
@endif

@if ($exam->findings)
    <h2>Achados</h2>
    <p>{{ $exam->findings }}</p>
@endif

@if ($exam->conclusion)
    <h2>Conclusão</h2>
    <p>{{ $exam->conclusion }}</p>
@endif

@if ($exam->results->isNotEmpty())
    <h2>Resultados</h2>
    <table class="results">
        <thead>
        <tr><th>Parâmetro</th><th>Valor</th><th>Unidade</th><th>Referência</th><th>Status</th></tr>
        </thead>
        <tbody>
        @foreach ($exam->results as $result)
            <tr>
                <td>{{ $result->parameter }}</td>
                <td>{{ $result->value }}</td>
                <td>{{ $result->unit ?? '—' }}</td>
                <td>{{ $result->reference_range ?? '—' }}</td>
                <td>{{ $result->status?->value ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="signature">
    <div class="line"></div>
    <div>{{ $exam->professional?->name ?? '—' }}</div>
    @if ($exam->professional?->professional?->crmv)
        <div class="muted">
            CRMV {{ $exam->professional->professional->crmv }}{{ $exam->professional->professional->crmv_state ? '/'.$exam->professional->professional->crmv_state : '' }}
        </div>
    @endif
</div>
</body>
</html>
