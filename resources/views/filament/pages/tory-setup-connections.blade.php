@if ($connections->isEmpty())
    <p>{{ __('No Claude connected yet.') }}</p>
@else
    <ul class="tory-connections">
        @foreach ($connections as $connection)
            <li wire:key="connection-{{ $connection->clientId }}">
                <div>
                    <strong>{{ $connection->name }}</strong>
                    @if ($connection->host)
                        <span>{{ $connection->host }}</span>
                    @endif
                    <span>{{ __('Connected on :date', ['date' => $connection->connectedAt->format(\App\Enums\Language::from(app()->getLocale())->dateTimeFormat())]) }}</span>
                </div>
                {{ ($this->disconnectAction)(['clientId' => $connection->clientId]) }}
            </li>
        @endforeach
    </ul>
@endif
