<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize Application</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#0b0f14; color:#e6edf3; margin:0; }
        .wrap { max-width: 520px; margin: 8vh auto; background: #0f1720; border: 1px solid #1f2a36; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
        header { padding: 20px 22px; border-bottom: 1px solid #1f2a36; display:flex; align-items:center; gap:12px; }
        header .app { font-weight: 600; }
        main { padding: 22px; }
        .scopes { margin: 12px 0 18px; padding-left: 18px; }
        .hint { color:#9fb0c0; font-size: .9rem; }
        .actions { display:flex; gap:12px; margin-top: 18px; }
        button { cursor:pointer; border:0; border-radius: 8px; padding: 12px 16px; font-weight:600; }
        .approve { background:#22c55e; color:#05250f; }
        .deny { background:#1f2a36; color:#e6edf3; border:1px solid #2b3b4c; }
        a { color:#7dd3fc; }
    </style>
    @csrf
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; form-action 'self'; frame-ancestors 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="data:,">
    <script>
        // Optional: auto-approve when coming from an extension to remove friction
        // Toggle via query param auto=1
        document.addEventListener('DOMContentLoaded', function () {
            const params = new URLSearchParams(location.search);
            if (params.get('auto') === '1') {
                const approve = document.getElementById('approve-form');
                if (approve) approve.submit();
            }
        });
    </script>
    @php /** @var \Laravel\Passport\Client $client */ @endphp
    @php /** @var \Illuminate\Contracts\Auth\Authenticatable $user */ @endphp
</head>
<body>
    <div class="wrap">
        <header>
            <div>
                <div class="app">{{ $client->name }}</div>
                <div class="hint">is requesting access to your account</div>
            </div>
        </header>
        <main>
            <p>Signed in as <strong>{{ $user->email ?? $user->name }}</strong>.</p>

            @if(!empty($scopes))
                <p>This application will be able to:</p>
                <ul class="scopes">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description ?? $scope->id }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="actions">
                <form id="approve-form" method="POST" action="{{ url('/oauth/authorize') }}">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="approve">Authorize</button>
                </form>

                <form id="deny-form" method="POST" action="{{ url('/oauth/authorize') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="deny">Cancel</button>
                </form>
            </div>

            <p class="hint" style="margin-top:14px;">You can revoke access at any time in your account settings.</p>
        </main>
    </div>
</body>
</html>
