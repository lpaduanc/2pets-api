@extends('emails.layout')
@section('title', 'Lembrete de Vermifugo')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Lembrete de Vermifugo</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        O vermifugo do <strong>{{ $petName }}</strong> esta proximo! Data prevista: <strong>{{ $dueDate }}</strong>.
    </p>
    <div style="background-color: #FFF3E0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #E65100; font-size: 14px; margin: 0;">
            &#128172; Nao esqueca de agendar a aplicacao do vermifugo para manter seu pet protegido contra parasitas.
        </p>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/tutor/search" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Buscar veterinario
                </a>
            </td>
        </tr>
    </table>
@endsection
