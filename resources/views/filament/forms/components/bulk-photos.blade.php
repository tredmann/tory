@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="bulkPhotos(@js(['statePath' => $statePath, 'messages' => [
            'tooMany' => __('At most :max photos per bulk import.'),
            'uploadFailed' => __('Upload failed.'),
            'unreadable' => __('This image cannot be read.'),
            'notAllowed' => __('Only JPEG, PNG, WebP or GIF images up to 12 MB are allowed.'),
        ], 'maxPhotos' => \App\Models\BulkImport::MAX_PHOTOS]))"
        x-on:bulk-photos-sync.window="syncMeta()"
        {{ $getExtraAttributeBag()->class(['tory-photo-picker', 'tory-bulk-photos']) }}
    >
        <div class="tory-bulk-photos-bar">
            <button
                type="button"
                class="tory-bulk-photos-add"
                x-on:click="$refs.input.click()"
            >{{ __('+ Add photos') }}</button>

            <span class="tory-bulk-photos-count" x-text="`${photoCount} / {{ \App\Models\BulkImport::MAX_PHOTOS }} {{ __('Photos') }}`"></span>
        </div>

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

        <div class="tory-bulk-photos-grid">
            <template x-for="tile in tiles" x-bind:key="tile.id">
                <div class="tory-bulk-photo" x-bind:class="{ 'is-uploading': tile.uploading }">
                    <template x-if="tile.message === null">
                        <img x-bind:src="tile.url" alt="" decoding="async">
                    </template>

                    <span
                        x-show="tile.uploading"
                        class="tory-photo-spinner"
                        role="status"
                        aria-label="{{ __('Uploading') }}"
                    ></span>

                    <template x-if="tile.message !== null">
                        <div class="tory-photo-rejection" role="alert">
                            <span class="tory-photo-rejection-name" x-text="tile.name"></span>
                            <span x-text="tile.message"></span>
                        </div>
                    </template>

                    <button
                        type="button"
                        class="tory-photo-remove"
                        x-bind:aria-label="tile.message === null ? `{{ __('Remove :name', ['name' => '${tile.name}']) }}` : '{{ __('Dismiss message') }}'"
                        x-on:click="remove(tile)"
                    >✕</button>
                </div>
            </template>
        </div>
    </div>
</x-dynamic-component>
