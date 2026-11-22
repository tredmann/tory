@use('App\Filament\Pages\HouseholdSettingsPage')

<div>
    @if ($shown)
        <x-filament-widgets::widget>
            <x-filament::section heading="{{ __('Set up Tory') }}">
                <x-slot name="afterHeader">
                    {{ $this->dismissAction }}
                </x-slot>

                <div class="tory-onboarding">
                    <p class="tory-onboarding-progress">{{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}</p>
                    <div class="tory-onboarding-bar" style="--tory-progress: {{ round($done / $total * 100) }}%"></div>

                    <ol class="tory-onboarding-steps">
                        @foreach ($steps as $step)
                            @php($isOpen = $step->key === $openKey)
                            <li @class(['tory-onboarding-step', 'tory-onboarding-step-done' => $step->done, 'tory-onboarding-step-open' => $isOpen])>
                                <span class="tory-onboarding-dot" aria-hidden="true">{{ $step->done ? '✓' : $loop->iteration }}</span>
                                @if ($step->done)
                                    <span class="tory-visually-hidden">{{ __('Completed') }}</span>
                                @endif
                                <div class="tory-onboarding-text">
                                    <p>
                                        <a href="{{ $step->url }}" class="tory-onboarding-title">{{ $step->title }}</a>
                                        @if ($step->done && $step->result !== null)
                                            <span class="tory-onboarding-result">· {{ $step->result }}</span>
                                        @endif
                                    </p>

                                    @if ($isOpen)
                                        <p class="tory-onboarding-explanation">{{ $step->explanation }}</p>
                                        <div class="tory-onboarding-actions">
                                            @if ($step->key === 'content-language')
                                                {{ $this->confirmContentLanguageAction }}
                                                <x-filament::button tag="a" color="gray" :href="HouseholdSettingsPage::getUrl()">
                                                    {{ __('Change') }}
                                                </x-filament::button>
                                            @else
                                                <x-filament::button tag="a" :href="$step->url">
                                                    {{ $step->actionLabel }}
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </x-filament::section>
        </x-filament-widgets::widget>
    @endif

    <x-filament-actions::modals />
</div>
