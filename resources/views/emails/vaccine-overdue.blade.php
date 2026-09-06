@extends('emails.layout')
@section('title', 'URGENTE: Vacina Vencida')
@section('content')
    <div style="background-color: #FFEBEE; border-radius: 12px; padding: 32px; text-align: center;">
        <span style="font-size: 48px;">&#9888;&#65039;</span>
        <h1 style="color: #C62828; font-size: 24px; margin: 16px 0;">URGENTE: Vacina Vencida!</h1>
        <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
            A vacina <strong>{{ $vaccineName }}</strong> do <strong>{{ $petName }}</strong> esta vencida desde <strong style="color: #C62828;">{{ $dueDate }}</strong>.
        </p>
        <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
            Procure um veterinario o mais rapido possivel para proteger a saude do seu pet.
        </p>
        <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
            <tr>
                <td style="background-color: #C62828; border-radius: 8px;">
                    <a href="{{ config('app.frontend_url') }}/tutor/search" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                        Buscar veterinario agora
                    </a>
                </td>
            </tr>
        </table>
    </div>
@endsection
