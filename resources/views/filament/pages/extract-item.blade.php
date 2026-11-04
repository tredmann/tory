<x-filament-panels::page>
    <form wire:submit="analyze" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="analyze">
            <x-filament::loading-indicator wire:loading wire:target="analyze" class="h-5 w-5" />
            Analysieren
        </x-filament::button>
    </form>

    @if ($this->extractedTitle !== null)
        <x-filament::section :heading="$this->extractedTitle">
            <p class="whitespace-pre-line">{{ $this->extractedDescription }}</p>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
