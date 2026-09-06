@extends('emails.layout')
@section('title', 'CRMV Nao Verificado')
@section('content')
    <h1 style="color: #333; font-size: 24px; margin: 0 0 16px 0;">Verificacao de CRMV</h1>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
        Dr(a). {{ $professionalName }}, nao foi possivel verificar seu CRMV {{ $crmvNumber }}.
    </p>
    <div style="background-color: #FFF3F3; border-left: 4px solid #FF6B6B; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
        <p style="color: #333; font-size: 14px; margin: 0;">
            <strong>Motivo:</strong> {{ $rejectionReason }}
        </p>
    </div>
    <p style="color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">
        Por favor, verifique o documento enviado e tente novamente. Certifique-se de que a imagem esta legivel e que os dados conferem.
    </p>
    <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
        <tr>
            <td style="background-color: #6C63FF; border-radius: 8px;">
                <a href="{{ config('app.frontend_url') }}/professional/profile" style="display: inline-block; padding: 14px 32px; color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 16px;">
                    Reenviar documento
                </a>
            </td>
        </tr>
    </table>
@endsection
