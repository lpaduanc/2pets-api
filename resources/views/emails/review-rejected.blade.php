@extends('emails.layout')
@section('title', 'Avaliacao Nao Aprovada')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Avaliacao Nao Aprovada</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
        Ola! Sua avaliacao para <strong>{{ $professionalName }}</strong> nao foi aprovada pela nossa equipe de moderacao.
    </p>
    <div style="background-color: #FFF3F3; border-left: 4px solid #FF6B6B; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #333; font-size: 14px; margin: 0;">
            <strong>Motivo:</strong> {{ $rejectionReason }}
        </p>
    </div>
    <p style="color: #555; font-size: 14px; line-height: 1.6; margin: 0;">
        Voce pode enviar uma nova avaliacao seguindo nossas diretrizes da comunidade.
    </p>
@endsection
