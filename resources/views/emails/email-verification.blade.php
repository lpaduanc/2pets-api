@extends('emails.layout')
@section('title', 'Confirme seu email')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Confirme seu email</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Clique no botao abaixo para confirmar seu endereco de email e ativar sua conta no 2pets.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $verificationUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Confirmar Email
                </a>
            </td>
        </tr>
    </table>
    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Este link expira em 24 horas. Se voce nao criou esta conta, ignore este email.
    </p>
@endsection
