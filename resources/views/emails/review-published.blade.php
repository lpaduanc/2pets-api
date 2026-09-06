@extends('emails.layout')
@section('title', 'Nova Avaliacao Recebida')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Nova Avaliacao!</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Voce recebeu uma nova avaliacao de <strong>{{ $tutorName }}</strong>!
    </p>
    <div style="background-color: #FFF9E6; border-radius: 8px; padding: 20px; margin-bottom: 24px; text-align: center;">
        <span style="font-size: 32px; color: #F5A623;">{{ str_repeat('&#9733;', $rating) }}{{ str_repeat('&#9734;', 5 - $rating) }}</span>
        <p style="color: #333; font-size: 18px; font-weight: 600; margin: 8px 0 0 0;">{{ $rating }}/5</p>
    </div>
    <div style="background-color: #F8F9FA; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #555; font-size: 14px; line-height: 1.6; margin: 0; font-style: italic;">
            "{{ Str::limit($comment, 200) }}"
        </p>
    </div>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ $professionalProfileUrl }}" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Ver avaliacao completa
                </a>
            </td>
        </tr>
    </table>
@endsection
