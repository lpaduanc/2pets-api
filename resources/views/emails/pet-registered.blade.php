@extends('emails.layout')
@section('title', 'Seu pet foi cadastrado no 2pets')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Seu pet foi cadastrado no 2pets</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 8px 0;">Ola, {{ $tutorName }}!</p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        {{ $professionalLabel }} cadastrou {{ $petName }} no 2pets para o seu atendimento.
        Voce ja pode acompanhar o historico do seu pet pela plataforma.
    </p>

    @if ($continuationUrl)
        <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
            Falta pouco: defina sua senha para acessar a conta que ja tem {{ $petName }} vinculado.
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
    @endif

    @if ($marketingOptIn)
        <p style="color: #555; font-size: 14px; line-height: 1.6; margin: 24px 0 0 0;">
            Aproveite tambem para conhecer os beneficios e parceiros do Clube 2pets dentro do app.
        </p>
    @endif

    <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
        Nao quer mais receber e-mails do 2pets? <a href="{{ $unsubscribeUrl }}" style="color: #6C63FF;">Descadastre-se aqui</a>.
    </p>
@endsection
