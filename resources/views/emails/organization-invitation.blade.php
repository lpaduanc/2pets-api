@extends('emails.layout')
@section('title', 'Convite para organização')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Você foi convidado!</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">
        Você foi convidado para fazer parte de <strong>{{ $organizationName }}</strong> no 2pets, como <strong>{{ $roleLabel }}</strong>.
    </p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Clique no botão abaixo para aceitar o convite. Se você ainda não tem conta no 2pets, o link vai te guiar pelo cadastro antes de concluir o vínculo.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $acceptUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Aceitar convite
                </a>
            </td>
        </tr>
    </table>
    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Este convite expira em 7 dias. Se você não esperava este e-mail, pode ignorá-lo com segurança.
    </p>
@endsection
