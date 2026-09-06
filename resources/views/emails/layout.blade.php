<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '2pets')</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F8F9FA; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #F8F9FA; padding: 32px 16px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 24px 0;">
                            <span style="font-size: 28px; font-weight: 800; color: #6C63FF; letter-spacing: -0.5px;">2pets</span>
                            <span style="font-size: 12px; color: #999; display: block; margin-top: 4px;">Plataforma Digital do Mundo Pet</span>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #FFFFFF; border-radius: 12px; padding: 40px 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                            @yield('content')
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding: 24px 0; color: #999; font-size: 12px; line-height: 1.6;">
                            <p style="margin: 0 0 8px 0;">&copy; {{ date('Y') }} 2pets - Plataforma Digital do Mundo Pet</p>
                            <p style="margin: 0;">
                                <a href="{{ config('app.url') }}/privacy" style="color: #6C63FF; text-decoration: none;">Politica de Privacidade</a>
                                &nbsp;&middot;&nbsp;
                                <a href="{{ config('app.url') }}/terms" style="color: #6C63FF; text-decoration: none;">Termos de Uso</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
