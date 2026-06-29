<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Login</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #abd1c6; color: #001e1d; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { width: min(92vw, 420px); border: 2px solid #001e1d; border-radius: 24px; background: #fffffe; padding: 32px; box-shadow: 10px 10px 0 #001e1d; }
        h1 { margin: 0 0 8px; font-size: 32px; line-height: 1.1; }
        p { margin: 0 0 24px; font-weight: 700; color: #004643; }
        label { display: block; margin: 16px 0 8px; font-size: 13px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
        input { width: 100%; box-sizing: border-box; border: 2px solid #001e1d; border-radius: 16px; padding: 14px 16px; font: inherit; font-weight: 700; outline: none; }
        input:focus { box-shadow: 4px 4px 0 #001e1d; }
        button, a.button { display: inline-flex; width: 100%; box-sizing: border-box; align-items: center; justify-content: center; margin-top: 20px; border: 2px solid #001e1d; border-radius: 16px; background: #001e1d; color: #fffffe; padding: 14px 16px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; text-decoration: none; cursor: pointer; }
        a.button { background: #f9bc60; color: #001e1d; }
        .error { margin-top: 16px; border: 2px solid #e16162; border-radius: 16px; padding: 12px; color: #e16162; font-weight: 800; }
    </style>
</head>
<body>
    <main>
        <h1>Admin login</h1>
        <p>Use the emergency admin username and password for the admin dashboard.</p>

        <form method="post" action="{{ route('admin.access.login.store') }}">
            @csrf

            <label for="username">Username or email</label>
            <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Open admin dashboard</button>
        </form>

        @if ($canRegister)
            <a class="button" href="{{ route('admin.access.register') }}">Register admin account</a>
        @endif

        @error('username')
            <div class="error">{{ $message }}</div>
        @enderror
    </main>
</body>
</html>
