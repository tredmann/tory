@use('App\Filament\Resources\Items\ItemResource')

<x-filament-widgets::widget>
    <x-filament::section heading="Lagerorte">
        <ul class="tory-locations">
            @foreach ($locations as $location)
                <li>
                    <a href="{{ ItemResource::getUrl('index', ['filters' => ['location' => ['value' => $location->getKey()]]]) }}" class="tory-location">
                        <span class="tory-location-name">{{ $location->name }}</span>
                        <span class="tory-location-count">{{ $location->items_count }}</span>
                        <span class="tory-location-bar" style="--tory-share: {{ round($location->items_count / $largest * 100) }}%"></span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
