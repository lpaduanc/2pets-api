@extends('emails.layout')
@section('title', 'Trial Expirado')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Seu periodo de teste expirou</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
        O periodo de teste do plano <strong>{{ $planName }}</strong> chegou ao fim.
        Sua conta foi convertida para o plano gratuito.
    </p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Assine agora para recuperar acesso a todas as funcionalidades premium e continuar cuidando dos seus pets com o melhor da tecnologia.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/subscription" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Assinar Plano Premium
                </a>
            </td>
        </tr>
    </table>
@endsection
