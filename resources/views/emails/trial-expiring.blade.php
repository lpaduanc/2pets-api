@extends('emails.layout')
@section('title', 'Seu Trial Expira em Breve')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Seu periodo de teste esta acabando!</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Seu periodo de teste do plano <strong>{{ $planName }}</strong> expira em <strong>2 dias</strong>.
        Adicione uma forma de pagamento para continuar aproveitando todos os recursos.
    </p>
    <div style="background-color: #F0F7FF; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #1565C0; font-size: 14px; margin: 0;">
            &#128161; Apos o periodo de teste, sua conta sera convertida para o plano gratuito automaticamente.
        </p>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/subscription" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Assinar agora
                </a>
            </td>
        </tr>
    </table>
@endsection
