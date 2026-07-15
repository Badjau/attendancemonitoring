<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#001e1d">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        <link rel="shortcut icon" href="{{ asset('images/logo-ico.png') }}" type="image/x-icon">
        <link rel="apple-touch-icon" href="{{ asset('images/logo-ico.png') }}">
        <link rel="manifest" href="{{ asset('manifest.json') }}">

        <title inertia>{{ config('app.name', 'Time Clock') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script defer src="{{ asset('js/kiosk-fullscreen.js') }}"></script>
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
