<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ state: $wire.$entangle(@js($getStatePath())) }"
        {{ $getExtraAttributeBag() }}
    >
        @foreach ($getPhotos() as $photo)
            <img
                src="{{ $photo['url'] }}"
                alt="Foto {{ $loop->iteration }}"
                data-path="{{ $photo['path'] }}"
                style="max-width: 8rem; display: inline-block"
            >
        @endforeach
    </div>
</x-dynamic-component>
