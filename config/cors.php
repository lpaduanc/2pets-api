<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',  // 2pets-website (Vite)
        'http://localhost:5174',  // 2pets-website (Vite) — porta alternativa: 5173 ocupada por outro stack local
        'http://localhost:9000',  // 2pets-app (legacy)
        'http://localhost:9200',  // 2pets-app (Docker)
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
        'http://127.0.0.1:9000',
        'http://127.0.0.1:9200',

        // Capacitor: a origem e o scheme do webview, nao um host de rede.
        // Android usa androidScheme "http"  -> http://localhost
        // iOS usa iosScheme "2pets"         -> 2pets://localhost
        // (ver 2pets-app/src-capacitor/capacitor.config.json)
        //
        // O Android era "https" e foi trocado para "http" porque o WebView
        // BLOQUEIA conteudo misto: a pagina em https://localhost nao carregava
        // NENHUMA imagem vinda da API em http (foto do pet, documento, exame).
        // O XHR passava so com aviso, entao os dados chegavam e so as imagens
        // sumiam — o que fazia o bug parecer de layout. `http://localhost`
        // continua sendo contexto seguro no Chromium (camera, geolocalizacao e
        // crypto seguem funcionando) e, em producao com API HTTPS, uma pagina
        // http carregando https nunca e bloqueada.
        //
        // `https://localhost` fica na lista de proposito: builds antigos
        // instalados em aparelho de teste continuam funcionando.
        'http://localhost',
        'https://localhost',
        'capacitor://localhost',
        '2pets://localhost',
    ],

    /*
     * Fora de producao, aceita o dev server do Quasar servido pelo IP da LAN
     * (live reload no celular fisico), sem precisar reescrever o IP a cada
     * troca de rede. Em producao a lista fica vazia.
     */
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
