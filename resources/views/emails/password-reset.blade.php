@extends('emails.layout')
@section('title', 'Redefinicao de Senha')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Redefinicao de Senha</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">Ola, {{ $userName }}!</p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Voce solicitou a redefinicao de senha da sua conta no 2pets. Clique no botao abaixo para criar uma nova senha.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $resetUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Redefinir Senha
                </a>
            </td>
        </tr>
    </table>
    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Este link expira em 1 hora. Se voce nao solicitou esta redefinicao, ignore este email.
    </p>
@endsection
