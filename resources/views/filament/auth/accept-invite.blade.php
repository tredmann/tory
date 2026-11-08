<x-filament-panels::page.simple>
    @if ($this->isLinkValid)
        {{ $this->content }}
    @else
        <p class="fi-simple-page-content-text">Dieser Link ist abgelaufen oder ungültig. Bitte frag nach einem neuen.</p>
    @endif
</x-filament-panels::page.simple>
