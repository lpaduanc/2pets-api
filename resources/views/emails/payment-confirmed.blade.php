@extends('emails.layout')
@section('title', 'Pagamento Confirmado')
@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <span style="display: inline-block; width: 64px; height: 64px; background-color: #E8F5E9; border-radius: 50%; line-height: 64px; font-size: 32px;">&#9989;</span>
    </div>
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0; text-align: center;">Pagamento Confirmado!</h1>
    <div style="background-color: #F8F9FA; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="color: #999; font-size: 14px; padding: 8px 0;">Valor</td>
                <td style="color: #333; font-size: 14px; font-weight: 600; text-align: right; padding: 8px 0;">R$ {{ number_format($amount, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td style="color: #999; font-size: 14px; padding: 8px 0;">Descricao</td>
                <td style="color: #333; font-size: 14px; text-align: right; padding: 8px 0;">{{ $description }}</td>
            </tr>
            <tr>
                <td style="color: #999; font-size: 14px; padding: 8px 0;">ID da Transacao</td>
                <td style="color: #333; font-size: 14px; text-align: right; padding: 8px 0;">{{ $transactionId }}</td>
            </tr>
        </table>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/appointments" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Ver meus agendamentos
                </a>
            </td>
        </tr>
    </table>
@endsection
