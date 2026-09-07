{{--
    Receituário veterinário em PDF (dompdf).

    Layout deliberadamente simples: dompdf não suporta flexbox nem grid, então tudo é tabela e
    bloco. Toda a formatação de data acontece aqui — `prescription_date` e `valid_until` são
    datas de calendário e nunca devem ganhar hora nem fuso.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Receituário — {{ $prescription->pet?->name }}</title>
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
        .meds th, .meds td { border: 1px solid #d8dee9; padding: 6px; text-align: left; vertical-align: top; }
        .meds th { background: #f2f5fb; font-size: 10px; text-transform: uppercase; }
        .notice { border: 1px solid #FFAE1F; background: #fff8e8; padding: 8px; margin-top: 12px; }
        .controlled { border: 1px solid #FA896B; background: #fdeeea; padding: 6px; margin-bottom: 12px; font-weight: bold; }
        .signature { margin-top: 48px; text-align: center; }
        .signature .line { border-top: 1px solid #1f2933; width: 260px; margin: 0 auto 4px; }
    </style>
</head>
<body>
<div class="header">
    <h1>Receituário Veterinário</h1>
    <div class="muted">Emitido em {{ $prescription->prescription_date?->format('d/m/Y') }}</div>
</div>

@if ($prescription->is_controlled)
    <div class="controlled">Receita de medicamento controlado — reter via na dispensação.</div>
@endif

<h2>Paciente</h2>
<table class="data">
    <tr>
        <td class="label">Animal</td>
        <td>{{ $prescription->pet?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Espécie / Raça</td>
        <td>{{ $prescription->pet?->species ?? '—' }}{{ $prescription->pet?->breed ? ' / '.$prescription->pet->breed : '' }}</td>
    </tr>
    <tr>
        <td class="label">Tutor</td>
        <td>{{ $prescription->pet?->user?->name ?? '—' }}</td>
    </tr>
</table>

<h2>Prescrição</h2>
<table class="data">
    <tr>
        <td class="label">Data</td>
        <td>{{ $prescription->prescription_date?->format('d/m/Y') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Válida até</td>
        <td>{{ $prescription->valid_until?->format('d/m/Y') ?? 'Sem prazo definido' }}</td>
    </tr>
</table>

<h2>Medicamentos</h2>
@if (count($medications) === 0)
    <p class="muted">Nenhum medicamento registrado nesta prescrição.</p>
@else
    <table class="meds">
        <thead>
        <tr>
            <th>Medicamento</th>
            <th>Dosagem</th>
            <th>Frequência</th>
            <th>Duração</th>
            <th>Orientações</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($medications as $medication)
            <tr>
                <td>{{ $medication['name'] ?? '—' }}</td>
                <td>{{ $medication['dosage'] ?? '—' }}</td>
                <td>{{ $medication['frequency'] ?? '—' }}</td>
                <td>{{ $medication['duration'] ?? '—' }}</td>
                <td>{{ $medication['instructions'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if ($prescription->general_instructions)
    <h2>Orientações gerais</h2>
    <p>{{ $prescription->general_instructions }}</p>
@endif

@if ($prescription->warnings)
    <div class="notice"><strong>Advertências:</strong> {{ $prescription->warnings }}</div>
@endif

<div class="signature">
    <div class="line"></div>
    <div>{{ $prescription->professional?->name ?? '—' }}</div>
    @if ($prescription->professional?->professional?->crmv)
        <div class="muted">
            CRMV {{ $prescription->professional->professional->crmv }}{{ $prescription->professional->professional->crmv_state ? '/'.$prescription->professional->professional->crmv_state : '' }}
        </div>
    @endif
</div>
</body>
</html>
