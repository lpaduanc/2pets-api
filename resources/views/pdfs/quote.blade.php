{{--
    Orçamento em PDF (dompdf) — docs/gap-simplesvet/24-orcamentos.md.

    Tudo tabela e bloco: dompdf não suporta flexbox nem grid (mesma restrição do receituário).
    `notes` (observação interna) NUNCA entra aqui — só `printed_notes`, que é o texto que a
    clínica escreveu para sair impresso.
--}}
@php
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento nº {{ $quote->number }} — {{ $quote->pet?->name }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #d8dee9; padding-bottom: 3px; }
        .header { border-bottom: 2px solid #5D87FF; padding-bottom: 8px; margin-bottom: 14px; }
        .header td { vertical-align: top; }
        .issuer { font-size: 14px; font-weight: bold; }
        .muted { color: #6b7684; font-size: 10px; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .data td { padding: 2px 0; vertical-align: top; }
        .data td.label { width: 130px; color: #6b7684; }
        .items th, .items td { border: 1px solid #d8dee9; padding: 6px; text-align: left; vertical-align: top; }
        .items th { background: #f2f5fb; font-size: 10px; text-transform: uppercase; }
        .items td.num, .items th.num { text-align: right; white-space: nowrap; }
        .totals { width: 45%; margin-left: 55%; margin-top: 8px; }
        .totals td { padding: 3px 0; }
        .totals .grand td { font-size: 14px; font-weight: bold; border-top: 1px solid #1f2933; padding-top: 6px; }
        .notice { border: 1px solid #FFAE1F; background: #fff8e8; padding: 8px; margin-top: 14px; }
        .status { border: 1px solid #d8dee9; background: #f2f5fb; padding: 6px; margin-bottom: 12px; font-weight: bold; }
        .signature { margin-top: 48px; }
        .signature td { text-align: center; width: 50%; }
        .signature .line { border-top: 1px solid #1f2933; width: 220px; margin: 0 auto 4px; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            <div class="issuer">{{ $issuer['name'] }}</div>
            @if ($issuer['document'])<div class="muted">{{ $issuer['document'] }}</div>@endif
            @if ($issuer['address'] || $issuer['city'])
                <div class="muted">{{ $issuer['address'] }}{{ $issuer['address'] && $issuer['city'] ? ' — ' : '' }}{{ $issuer['city'] }}{{ $issuer['state'] ? '/'.$issuer['state'] : '' }}</div>
            @endif
            @if ($issuer['phone'])<div class="muted">Tel. {{ $issuer['phone'] }}</div>@endif
        </td>
        <td class="right">
            <h1>Orçamento nº {{ $quote->number }}</h1>
            <div class="muted">Versão {{ $quote->version }} · emitido em {{ ($quote->sent_at ?? $quote->created_at)?->format('d/m/Y') }}</div>
            <div class="muted">Válido até {{ $quote->valid_until?->format('d/m/Y') ?? 'sem prazo definido' }}</div>
        </td>
    </tr>
</table>

@if ($status && ! in_array($status->value, ['draft', 'sent', 'viewed'], true))
    <div class="status">Situação: {{ $status->label() }}</div>
@endif

<h2>Tutor e animal</h2>
<table class="data">
    <tr><td class="label">Tutor</td><td>{{ $quote->client?->name ?? '—' }}</td></tr>
    <tr><td class="label">Animal</td><td>{{ $quote->pet?->name ?? '—' }}</td></tr>
    <tr>
        <td class="label">Espécie / Raça</td>
        <td>{{ $quote->pet?->species ?? '—' }}{{ $quote->pet?->breed ? ' / '.$quote->pet->breed : '' }}</td>
    </tr>
</table>

<h2>Itens</h2>
@if ($quote->items->isEmpty())
    <p class="muted">Nenhum item neste orçamento.</p>
@else
    <table class="items">
        <thead>
        <tr>
            <th>Descrição</th>
            <th class="num">Qtd.</th>
            <th class="num">Valor unit.</th>
            <th class="num">Desconto</th>
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($quote->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ $qty($item->quantity) }}</td>
                <td class="num">{{ $money($item->unit_price) }}</td>
                <td class="num">{{ (float) $item->discount > 0 ? $money($item->discount) : '—' }}</td>
                <td class="num">{{ $money($item->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $money($quote->subtotal) }}</td></tr>
        @if ((float) $quote->discount_amount > 0)
            <tr><td>Desconto</td><td class="right">− {{ $money($quote->discount_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ $money($quote->total) }}</td></tr>
    </table>
@endif

@if ($quote->printed_notes)
    <h2>Observações</h2>
    <p>{!! nl2br(e($quote->printed_notes)) !!}</p>
@endif

<div class="notice">
    Este orçamento não é cobrança. Os valores valem até {{ $quote->valid_until?->format('d/m/Y') ?? 'a data combinada com a clínica' }}
    e podem mudar caso o procedimento exija etapas não previstas — nesse caso a clínica emite uma nova versão para sua aprovação.
</div>

<table class="signature">
    <tr>
        <td><div class="line"></div>{{ $quote->createdBy?->name ?? $issuer['name'] }}</td>
        <td><div class="line"></div>{{ $quote->client?->name ?? 'Tutor' }}</td>
    </tr>
</table>
</body>
</html>
