<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Verifique seu email - 2Pets</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 0;
            color: #333333;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            padding: 20px 0;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #6366f1;
            text-decoration: none;
        }

        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 40px;
            text-align: center;
        }

        h1 {
            color: #1e293b;
            font-size: 24px;
            margin-bottom: 20px;
        }

        p {
            font-size: 16px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 20px;
        }

        .btn {
            display: inline-block;
            background-color: #6366f1;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
        }

        .footer {
            text-align: center;
            padding: 20px 0;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <a href="#" class="logo">2Pets</a>
        </div>
        <div class="card">
            <h1>Bem-vindo à 2Pets!</h1>
            <p>Olá {{ $user->name }},</p>
            <p>Obrigado por se cadastrar. Para começar a usar sua conta, por favor verifique seu endereço de email
                clicando no botão abaixo.</p>

            <a href="{{ config('app.frontend_url', 'http://localhost:9000') }}/verify-email?token={{ $token }}"
                class="btn">Verificar Email</a>

            <p>Se você não criou uma conta, pode ignorar este email.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} 2Pets. Todos os direitos reservados.
        </div>
    </div>
</body>

</html>