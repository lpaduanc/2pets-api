@extends('emails.layout')
@section('title', 'Falha no Pagamento')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Falha no Pagamento</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
        Nao foi possivel processar seu pagamento de <strong>R$ {{ number_format($amount, 2, ',', '.') }}</strong>.
    </p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">
        Referente a: {{ $description }}
    </p>
    <div style="background-color: #FFF3F3; border-left: 4px solid #FF6B6B; border-radius: 4px; padding: 16px; margin: 24px 0;">
        <p style="color: #333; font-size: 14px; margin: 0 0 8px 0;"><strong>O que fazer:</strong></p>
        <ul style="color: #555; font-size: 14px; margin: 0; padding-left: 20px;">
            <li>Verifique se os dados do cartao estao corretos</li>
            <li>Confirme se ha saldo ou limite disponivel</li>
            <li>Tente outro metodo de pagamento</li>
        </ul>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/wallet" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Verificar dados de pagamento
                </a>
            </td>
        </tr>
    </table>
@endsection
