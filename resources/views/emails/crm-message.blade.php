@extends('emails.layout')
@section('title', $subjectLine)
@section('content')
    <div style="color: #555; font-size: 16px; line-height: 1.6; white-space: pre-line;">{{ $bodyText }}</div>

    @if ($unsubscribeUrl)
        <p style="color: #999; font-size: 13px; margin: 24px 0 0 0; text-align: center;">
            Nao quer mais receber e-mails promocionais do 2pets? <a href="{{ $unsubscribeUrl }}" style="color: #6C63FF;">Descadastre-se aqui</a>.
        </p>
    @endif
@endsection
