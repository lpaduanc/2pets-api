@extends('emails.layout')
@section('title', 'Bem-vindo ao 2pets!')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Bem-vindo ao 2pets, {{ $userName }}!</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Sua conta foi criada com sucesso. Estamos felizes em ter voce na maior plataforma pet do Brasil!
    </p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Comece agora cadastrando seus pets e descubra profissionais incriveis perto de voce.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/pets/add" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Cadastrar meu pet
                </a>
            </td>
        </tr>
    </table>
@endsection
