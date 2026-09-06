@extends('emails.layout')
@section('title', 'Lembrete de Vacina')
@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <span style="display: inline-block; width: 64px; height: 64px; background-color: #E3F2FD; border-radius: 50%; line-height: 64px; font-size: 32px;">&#128137;</span>
    </div>
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0; text-align: center;">Lembrete de Vacina</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Atencao! A vacina <strong>{{ $vaccineName }}</strong> do <strong>{{ $petName }}</strong> vence em <strong>{{ $daysUntil }} dias</strong> ({{ $dueDate }}).
    </p>
    <div style="background-color: #E8F5E9; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #2E7D32; font-size: 14px; margin: 0;">
            &#128161; Agende com um veterinario para manter a saude do seu pet em dia!
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
