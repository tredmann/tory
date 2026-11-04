<?php

use App\Ai\Agents\Assistant;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|string|max:2000')]
    public string $prompt = '';

    public ?string $answer = null;

    public ?string $error = null;

    public function ask(): void
    {
        $this->validate();

        $this->answer = null;
        $this->error = null;

        try {
            $this->answer = (string) Assistant::make()->prompt($this->prompt);
        } catch (\Throwable $e) {
            report($e);

            $this->error = app()->hasDebugModeEnabled()
                ? $e->getMessage()
                : __('The assistant is unavailable right now. Please try again later.');
        }
    }
};
?>

<div class="card bg-base-100 shadow-sm">
    <div class="card-body gap-4">
        <h2 class="card-title">{{ __('Assistant') }}</h2>

        <form wire:submit="ask" class="flex flex-col gap-3">
            <fieldset class="fieldset">
                <label class="label" for="prompt">{{ __('Ask something') }}</label>
                <textarea id="prompt" wire:model="prompt" rows="3" class="textarea w-full @error('prompt') textarea-error @enderror" placeholder="{{ __('What can Laravel AI do?') }}"></textarea>
                <x-input-error for="prompt" />
            </fieldset>

            <div class="card-actions justify-end">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="ask">
                    <span wire:loading wire:target="ask" class="loading loading-spinner loading-sm"></span>
                    {{ __('Send') }}
                </button>
            </div>
        </form>

        @if ($error)
            <div role="alert" class="alert alert-error alert-soft text-sm">
                <span>{{ $error }}</span>
            </div>
        @endif

        @if ($answer !== null)
            <div class="rounded-box bg-base-200 p-4 whitespace-pre-wrap">{{ $answer }}</div>
        @endif
    </div>
</div>
