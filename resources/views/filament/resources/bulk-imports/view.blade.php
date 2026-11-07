@php
    use App\Enums\BulkImportStatus;
    use App\Filament\Resources\AiProviders\AiProviderResource;
    use App\Models\Import;
@endphp

<x-filament-panels::page>
    @if ($bulkImport->status === BulkImportStatus::Grouping)
        <div wire:poll.5s class="tory-review-waiting" role="status">
            <x-filament::loading-indicator class="tory-review-waiting-indicator" />
            Wird gruppiert …
        </div>
    @else
        @if ($bulkImport->failure_reason !== null)
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle">
                <x-slot name="description">
                    {{ $bulkImport->failureMessage() }}
                    <a href="{{ AiProviderResource::getUrl('index') }}" class="tory-review-note-link">Provider prüfen</a>
                </x-slot>
            </x-filament::callout>
        @elseif ($bulkImport->grouping_incomplete)
            <x-filament::callout
                color="warning"
                icon="heroicon-o-exclamation-triangle"
                description="Die KI-Prüfung ist nicht vollständig gelaufen. Ein Teil der Gruppen beruht nur auf der Aufnahmezeit."
            />
        @endif

        <div class="tory-review-cards">
            @foreach ($cards as $index => $card)
                @php($groupId = $card['group']->id)

                <section
                    wire:key="card-{{ $groupId }}"
                    @class(['tory-review-card', 'is-over-limit' => $card['overLimit']])
                    aria-labelledby="card-heading-{{ $groupId }}"
                >
                    <header class="tory-review-card-header">
                        <h2 id="card-heading-{{ $groupId }}" class="tory-review-card-title">
                            Gruppe {{ $index + 1 }} · {{ $card['remaining'] }} {{ $card['remaining'] === 1 ? 'Foto' : 'Fotos' }}
                        </h2>

                        @if ($card['group']->mark !== null)
                            <x-filament::badge color="info">{{ $card['group']->mark->getLabel() }}</x-filament::badge>
                        @endif

                        @if (! $loop->last)
                            <button
                                type="button"
                                class="tory-review-merge"
                                wire:click="mergeWithNext(@js($groupId))"
                            >Mit nächster zusammenlegen ↓</button>
                        @endif
                    </header>

                    @if ($card['overLimit'])
                        <p class="tory-review-limit">Höchstens {{ Import::MAX_PHOTOS }} Fotos pro Import. Bitte aufteilen.</p>
                    @endif

                    <ul
                        class="tory-review-photos"
                        wire:sort="sortPhoto"
                        wire:sort:group="bulk-photos"
                        wire:sort:group-id="{{ $groupId }}"
                    >
                        @foreach ($card['photos'] as $position => $photo)
                            <li
                                wire:key="photo-{{ $photo['path'] }}"
                                wire:sort:item="{{ $photo['path'] }}"
                                @class(['tory-review-photo', 'is-discarded' => $photo['discarded']])
                            >
                                <img src="{{ $photo['url'] }}" alt="Foto {{ $position + 1 }}" loading="lazy" draggable="false">

                                @if ($position > 0)
                                    <button
                                        type="button"
                                        class="tory-review-split"
                                        wire:sort:ignore
                                        wire:click="splitBefore(@js($photo['path']))"
                                        aria-label="Vor Foto {{ $position + 1 }} teilen"
                                        title="Hier teilen"
                                    >✂</button>
                                @endif

                                @if ($photo['discarded'])
                                    <button
                                        type="button"
                                        class="tory-review-restore"
                                        wire:sort:ignore
                                        wire:click="restore(@js($photo['path']))"
                                    >rückgängig</button>
                                @else
                                    <button
                                        type="button"
                                        class="tory-review-discard"
                                        wire:sort:ignore
                                        wire:click="discard(@js($photo['path']))"
                                        aria-label="Foto {{ $position + 1 }} verwerfen"
                                        title="Verwerfen"
                                    >✕</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
