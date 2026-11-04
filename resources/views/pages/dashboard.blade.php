<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    //
};
?>

<div class="flex flex-col gap-6">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h1 class="card-title text-2xl">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</h1>
            <p class="text-base-content/70">{{ __('You are signed in to :app.', ['app' => config('app.name')]) }}</p>
        </div>
    </div>
</div>
