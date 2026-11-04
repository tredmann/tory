<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-200 font-sans antialiased">
        <div class="navbar bg-base-100 shadow-sm">
            <div class="flex-1">
                <a href="{{ route('home') }}" class="btn btn-ghost text-xl" wire:navigate>{{ config('app.name') }}</a>
            </div>
            <div class="flex-none gap-2">
                @auth
                    @if (Route::has('dashboard'))
                        <a href="{{ route('dashboard') }}" class="btn btn-ghost" wire:navigate>Dashboard</a>
                    @endif
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-ghost">
                            {{ auth()->user()->name }}
                        </div>
                        <ul tabindex="0" class="menu dropdown-content z-10 mt-3 w-52 rounded-box bg-base-100 p-2 shadow">
                            @if (Route::has('logout'))
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="w-full text-left">Log out</button>
                                    </form>
                                </li>
                            @endif
                        </ul>
                    </div>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="btn btn-ghost">Log in</a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">Register</a>
                    @endif
                @endauth
            </div>
        </div>

        <main class="container mx-auto max-w-5xl p-6">
            {{ $slot }}
        </main>
    </body>
</html>
