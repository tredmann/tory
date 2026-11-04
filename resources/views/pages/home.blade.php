<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component
{
    //
};
?>

<div class="hero rounded-box bg-base-100 py-16 shadow-sm">
    <div class="hero-content text-center">
        <div class="max-w-md">
            <h1 class="text-5xl font-bold">{{ config('app.name') }}</h1>
            <p class="py-6 text-base-content/70">
                Laravel, Livewire and daisyUI on FrankenPHP, with the Laravel AI SDK ready to go.
            </p>
            <div class="flex justify-center gap-2">
                @auth
                    @if (Route::has('dashboard'))
                        <a href="{{ route('dashboard') }}" class="btn btn-primary" wire:navigate>Go to dashboard</a>
                    @endif
                @else
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">Get started</a>
                    @endif
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="btn btn-ghost">Log in</a>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</div>
