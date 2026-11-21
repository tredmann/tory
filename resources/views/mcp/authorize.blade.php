@php
    $redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? '');
    $redirectHost = is_string($redirectUri) ? parse_url($redirectUri, PHP_URL_HOST) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Allow access?') }} - {{ config('app.name') }}</title>
    <style>
        :root {
            --background: #f9fafb;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --primary: #f59e0b;
            --primary-hover: #d97706;
            --primary-text: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #09090b;
                --card: #18181b;
                --border: #27272a;
                --text: #f4f4f5;
                --muted: #a1a1aa;
                --primary: #fbbf24;
                --primary-hover: #f59e0b;
                --primary-text: #18181b;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: var(--background);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, sans-serif;
            line-height: 1.5;
        }

        main {
            width: 100%;
            max-width: 28rem;
            padding: 2rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }

        h1 { margin: 0 0 1rem; font-size: 1.5rem; font-weight: 700; }
        p { margin: 0 0 1rem; }
        .muted { color: var(--muted); font-size: 0.875rem; }
        .actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
        .actions form { flex: 1; margin: 0; }

        button {
            width: 100%;
            padding: 0.625rem 1rem;
            border-radius: 0.5rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text);
        }

        button.primary { border-color: var(--primary); background: var(--primary); color: var(--primary-text); }
        button.primary:hover { background: var(--primary-hover); }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Allow access?') }}</h1>

    <p>
        @if ($redirectHost)
            {{ __('“:client” (:host) wants to access your Tory.', ['client' => $client->name, 'host' => $redirectHost]) }}
        @else
            {{ __('“:client” wants to access your Tory.', ['client' => $client->name]) }}
        @endif
        {{ __('It can find items, move them and create spots.') }}
    </p>

    <p class="muted">{{ __('Signed in as :email', ['email' => $user->email]) }}</p>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">{{ __('Deny') }}</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">{{ __('Allow') }}</button>
        </form>
    </div>
</main>
</body>
</html>
