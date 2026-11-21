<x-filament-widgets::widget>
    <form method="get" action="{{ $action }}" role="search" class="tory-item-search">
        <x-filament::input.wrapper :prefix-icon="\Filament\Support\Icons\Heroicon::MagnifyingGlass">
            <x-filament::input type="search" name="search" placeholder="{{ __('Where is …?') }}" aria-label="{{ __('Search items') }}" />
        </x-filament::input.wrapper>
    </form>
</x-filament-widgets::widget>
