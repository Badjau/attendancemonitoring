<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Register Admin</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #abd1c6; color: #001e1d; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { width: min(92vw, 460px); border: 2px solid #001e1d; border-radius: 24px; background: #fffffe; padding: 32px; box-shadow: 10px 10px 0 #001e1d; }
        h1 { margin: 0 0 8px; font-size: 32px; line-height: 1.1; }
        p { margin: 0 0 24px; font-weight: 700; color: #004643; }
        label { display: block; margin: 16px 0 8px; font-size: 13px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
        input { width: 100%; box-sizing: border-box; border: 2px solid #001e1d; border-radius: 16px; padding: 14px 16px; font: inherit; font-weight: 700; outline: none; }
        input:focus { box-shadow: 4px 4px 0 #001e1d; }
        button, a { display: inline-flex; width: 100%; box-sizing: border-box; align-items: center; justify-content: center; margin-top: 20px; border: 2px solid #001e1d; border-radius: 16px; background: #001e1d; color: #fffffe; padding: 14px 16px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; text-decoration: none; cursor: pointer; }
        a { background: #fffffe; color: #001e1d; }
        .error { margin-top: 8px; color: #e16162; font-weight: 800; }
    </style>
</head>
<body>
    <main>
        <h1>Register admin</h1>
        <p>Create the emergency username and password for the admin dashboard.</p>

        <form method="post" action="{{ route('admin.access.register.store') }}" autocomplete="off">
            @csrf

            <label for="name">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" autocomplete="off" required autofocus>
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label for="username">Username</label>
            <input id="username" name="username" value="{{ old('username') }}" autocomplete="off" required>
            @error('username') <div class="error">{{ $message }}</div> @enderror

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="off">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="off" required>
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="off" required>

            <button type="submit">Create admin account</button>
        </form>

        <a href="{{ route('timeclock.unlock') }}">Back to unlock</a>
    </main>
</body>
</html>
