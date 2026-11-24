@php
    use App\Enums\BulkImportStatus;
    use App\Filament\Resources\AiProviders\AiProviderResource;
    use App\Models\Import;
@endphp

<x-filament-panels::page>
    @if ($bulkImport->status === BulkImportStatus::Grouping)
        <div wire:poll.5s class="tory-review-waiting" role="status">
            <x-filament::loading-indicator class="tory-review-waiting-indicator" />
            {{ __('Grouping…') }}
        </div>
    @else
        @if ($bulkImport->failure_reason !== null)
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle">
                <x-slot name="description">
                    {{ $bulkImport->failureMessage() }}
                    @if (AiProviderResource::canAccess())
                        <a href="{{ AiProviderResource::getUrl('index') }}" class="tory-review-note-link">{{ __('Check provider') }}</a>
                    @endif
                </x-slot>
            </x-filament::callout>
        @elseif ($bulkImport->grouping_incomplete)
            <x-filament::callout
                color="warning"
                icon="heroicon-o-exclamation-triangle"
                description="{{ __('The AI check did not run completely. Some groups are based on capture time only.') }}"
            />
        @endif

        <div class="tory-review-cards">
            @foreach ($cards as $index => $card)
                @php($groupId = $card->group->id)

                <section
                    wire:key="card-{{ $groupId }}"
                    @class(['tory-review-card', 'is-over-limit' => $card->overLimit])
                    aria-labelledby="card-heading-{{ $groupId }}"
                >
                    <header class="tory-review-card-header">
                        <h2 id="card-heading-{{ $groupId }}" class="tory-review-card-title">
                            {{ __('Group :number', ['number' => $index + 1]) }} · {{ $card->remaining }} {{ $card->remaining === 1 ? __('Photo') : __('Photos') }}
                        </h2>

                        @if ($card->group->mark !== null)
                            <x-filament::badge color="info">{{ $card->group->mark->getLabel() }}</x-filament::badge>
                        @endif

                        @if (! $loop->last)
                            <button
                                type="button"
                                class="tory-review-merge"
                                wire:click="mergeWithNext(@js($groupId), @js($cards[$index + 1]->group->id))"
                            >{{ __('Merge with next') }} <span aria-hidden="true">↓</span></button>
                        @endif
                    </header>

                    @if ($card->overLimit)
                        <p class="tory-review-limit">{{ __('At most :max photos per import. Please split.', ['max' => Import::MAX_PHOTOS]) }}</p>
                    @endif

                    <ul
                        class="tory-review-photos"
                        wire:sort="sortPhoto"
                        wire:sort:group="bulk-photos"
                        wire:sort:group-id="{{ $groupId }}"
                    >
                        @foreach ($card->photos as $position => $photo)
                            <li
                                wire:key="photo-{{ $photo->path }}"
                                wire:sort:item="{{ $photo->path }}"
                                @class(['tory-review-photo', 'is-discarded' => $photo->discarded])
                            >
                                <img src="{{ $photo->url }}" alt="{{ __('Photo :number', ['number' => $position + 1]) }}" loading="lazy" draggable="false">

                                @if ($position > 0)
                                    <button
                                        type="button"
                                        class="tory-review-split"
                                        wire:sort:ignore
                                        wire:click="splitBefore(@js($photo->path))"
                                        aria-label="{{ __('Split before photo :number', ['number' => $position + 1]) }}"
                                        title="{{ __('Split here') }}"
                                    >✂</button>
                                @endif

                                @if ($photo->discarded)
                                    <button
                                        type="button"
                                        class="tory-review-restore"
                                        wire:sort:ignore
                                        wire:click="restore(@js($photo->path))"
                                        aria-label="{{ __('Restore photo :number', ['number' => $position + 1]) }}"
                                    >{{ __('Undo') }}</button>
                                @else
                                    <button
                                        type="button"
                                        class="tory-review-discard"
                                        wire:sort:ignore
                                        wire:click="discard(@js($photo->path))"
                                        aria-label="{{ __('Discard photo :number', ['number' => $position + 1]) }}"
                                        title="{{ __('Discard') }}"
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
