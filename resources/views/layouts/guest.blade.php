<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-200 font-sans antialiased">
        <main class="flex min-h-screen flex-col items-center justify-center gap-6 p-6">
            <a href="{{ route('home') }}" class="text-2xl font-semibold">{{ config('app.name') }}</a>

            <div class="card w-full max-w-md bg-base-100 shadow-xl">
                <div class="card-body">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </body>
</html>
