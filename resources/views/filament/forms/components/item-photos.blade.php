@php
    $statePath = $getStatePath();
    $photos = $getPhotos();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        wire:key="{{ $statePath }}.{{ md5(implode("\n", array_column($photos, 'path'))) }}"
        x-data="itemPhotos(@js(['statePath' => $statePath, 'photos' => $photos], JSON_UNESCAPED_SLASHES))"
        x-on:item-photos-saved.window="reset()"
        {{ $getExtraAttributeBag()->class(['tory-photo-picker']) }}
    >
        <template x-for="(tile, index) in tiles" x-bind:key="tile.id">
            <div
                class="tory-photo-tile"
                x-bind:class="{
                    'is-overview': overview === tile.id,
                    'is-marked': tile.marked,
                    'is-uploading': tile.uploading,
                    'is-rejected': tile.message !== null,
                }"
            >
                <template x-if="tile.message === null">
                    <button
                        type="button"
                        class="tory-photo"
                        x-bind:aria-pressed="String(overview === tile.id)"
                        x-bind:aria-label="`Foto ${index + 1} als Übersichtsfoto wählen`"
                        x-bind:disabled="! isAvailable(tile)"
                        x-on:click="choose(tile)"
                    >
                        <img x-bind:src="tile.url" alt="">
                    </button>
                </template>

                <span
                    x-show="tile.uploading"
                    class="tory-photo-spinner"
                    role="status"
                    aria-label="Wird hochgeladen"
                ></span>

                <button
                    x-show="isAvailable(tile)"
                    type="button"
                    class="tory-photo-remove"
                    aria-label="Foto entfernen"
                    x-on:click="remove(tile); $nextTick(() => $el.parentElement.querySelector('.tory-photo-undo').focus())"
                >✕</button>

                <button
                    x-show="tile.marked"
                    type="button"
                    class="tory-photo-undo"
                    x-on:click="undo(tile); $nextTick(() => $el.parentElement.querySelector('.tory-photo-remove').focus())"
                >rückgängig</button>

                <template x-if="tile.message !== null">
                    <div class="tory-photo-rejection" role="alert">
                        <span class="tory-photo-rejection-name" x-text="tile.name"></span>
                        <span x-text="tile.message"></span>
                        <button
                            type="button"
                            class="tory-photo-remove"
                            aria-label="Meldung schließen"
                            x-on:click="dismiss(tile)"
                        >✕</button>
                    </div>
                </template>
            </div>
        </template>

        <button
            x-show="canAdd"
            type="button"
            class="tory-photo-add"
            x-on:click="$refs.input.click()"
        >+ Foto hinzufügen</button>

        <input
            x-ref="input"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp,image/gif"
            hidden
            x-on:change="add($event)"
        >

        <p
            x-show="message !== null"
            x-text="message"
            class="tory-photo-message"
            role="alert"
        ></p>
    </div>
</x-dynamic-component>
