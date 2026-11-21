<x-filament-panels::page.simple>
    @if ($this->isLinkValid)
        {{ $this->content }}
    @else
        <p class="fi-simple-page-content-text">{{ __('This link has expired or is invalid. Please ask for a new one.') }}</p>
    @endif
</x-filament-panels::page.simple>
