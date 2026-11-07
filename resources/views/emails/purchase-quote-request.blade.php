@extends('emails.layout')
@section('title', 'Pedido de cotação')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Pedido de cotação nº {{ $number }}</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">
        Olá, {{ $supplierName }}. <strong>{{ $clinicName }}</strong> pede a sua cotação para os itens abaixo, com resposta até <strong>{{ $validUntil }}</strong>.
    </p>
    <table cellpadding="6" cellspacing="0" style="width: 100%; margin: 16px 0; border-collapse: collapse; font-size: 14px; color: #333;">
        @foreach ($items as $item)
            <tr style="border-bottom: 1px solid #EEE;">
                <td>{{ $item['name'] }}</td>
                <td style="text-align: right; white-space: nowrap;">{{ $item['quantity'] }} {{ $item['unit'] }}</td>
            </tr>
        @endforeach
    </table>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Informe o preço de cada item, o frete, o prazo de entrega e a condição de pagamento pelo link abaixo. Não é preciso criar conta.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $responseUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Responder cotação
                </a>
            </td>
        </tr>
    </table>
    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Se não puder atender, use o mesmo link para recusar. O link é pessoal: não o encaminhe.
    </p>
@endsection
