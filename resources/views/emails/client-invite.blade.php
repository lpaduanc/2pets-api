@extends('emails.layout')
@section('title', 'Você foi cadastrado no 2pets')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Você foi cadastrado no 2pets</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">Ola, {{ $clientName }}!</p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        {{ $professionalLabel }} cadastrou voce como cliente no 2pets. Complete seu cadastro para
        acompanhar o historico dos seus pets pela plataforma.
    </p>

    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $continuationUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Completar meu cadastro
                </a>
            </td>
        </tr>
    </table>

    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Nao quer mais receber e-mails do 2pets? <a href="{{ $unsubscribeUrl }}" style="color: #6C63FF;">Descadastre-se aqui</a>.
    </p>
@endsection
