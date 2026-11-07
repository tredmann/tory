@if ($connections->isEmpty())
    <p>Noch kein Claude verbunden.</p>
@else
    <ul class="tory-connections">
        @foreach ($connections as $connection)
            <li wire:key="connection-{{ $connection->clientId }}">
                <div>
                    <strong>{{ $connection->name }}</strong>
                    @if ($connection->host)
                        <span>{{ $connection->host }}</span>
                    @endif
                    <span>Verbunden am {{ $connection->connectedAt->format('d.m.Y H:i') }}</span>
                </div>
                {{ ($this->disconnectAction)(['clientId' => $connection->clientId]) }}
            </li>
        @endforeach
    </ul>
@endif
