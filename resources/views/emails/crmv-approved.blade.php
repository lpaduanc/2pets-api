@extends('emails.layout')
@section('title', 'CRMV Verificado!')
@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <span style="display: inline-block; width: 64px; height: 64px; background-color: #E8F5E9; border-radius: 50%; line-height: 64px; font-size: 32px;">&#9989;</span>
    </div>
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0; text-align: center;">CRMV Verificado com Sucesso!</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
        Parabens, Dr(a). {{ $professionalName }}!
    </p>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Seu CRMV <strong>{{ $crmvNumber }}/{{ $crmvState }}</strong> foi verificado com sucesso pela equipe 2pets.
        Seu perfil agora exibe o selo de verificacao, aumentando sua credibilidade para os tutores.
    </p>
    <div style="background-color: #F0F7FF; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #333; font-size: 14px; margin: 0;">
            <strong>&#128737; Selo "Verificado"</strong> — visivel no seu perfil publico e nos resultados de busca.
        </p>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/professional/dashboard" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Ver meu perfil
                </a>
            </td>
        </tr>
    </table>
@endsection
