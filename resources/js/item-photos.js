import '../css/photo-picker.css';
import { addTiles, exceedsLimit, finishUpload, registerUploadCount, rejectTile, revokePreview, uploadTile } from './photo-upload.js';

const storedTiles = (photos) =>
    photos.map((photo) => ({
        id: photo.path,
        url: photo.url,
        name: null,
        marked: false,
        uploading: false,
        message: null,
    }));

// A finish request already under way can still store a cancelled upload on the server, so its key stays marked.
const abandonedUploads = new Set();

const itemPhotos = ({ statePath, photos, maxPhotos, messages }) => {
    // Uploads run one after the other: the server keeps new photos in the order they finish.
    let queue = Promise.resolve();
    let wire;
    let destroyed = false;
    // The photos whose ✕ took the overview mark away, oldest first, for as long as "rückgängig" should give it back.
    let displaced = [];

    return {
        tiles: storedTiles(photos),
        overview: photos[0]?.path ?? null,
        message: null,

        /**
         * A new instance shows no marks and no uploads, so the server must hold none either:
         * the page restarts this component whenever the stored list changed, even from another tab.
         */
        init() {
            wire = this.$wire;
            wire.$set(`${statePath}.overview`, this.overview, false);
            wire.$set(`${statePath}.removed`, [...abandonedUploads], false);
            wire.$set(`${statePath}.uploads`, [], false);
        },

        get canAdd() {
            return this.photoCount < maxPhotos;
        },

        get photoCount() {
            return this.tiles.filter((tile) => !tile.marked && tile.message === null).length;
        },

        isAvailable(tile) {
            return !tile.marked && !tile.uploading && tile.message === null;
        },

        choose(tile) {
            if (!this.isAvailable(tile)) {
                return;
            }

            displaced = [];
            this.overview = tile.id;
            this.syncMarks();
        },

        remove(tile) {
            tile.marked = true;

            if (this.overview === tile.id) {
                displaced.push(tile.id);

                const position = this.tiles.findIndex((other) => other.id === tile.id);
                const next = [...this.tiles.slice(position + 1), ...this.tiles.slice(0, position)].find((other) =>
                    this.isAvailable(other),
                );

                this.overview = next?.id ?? null;
            }

            this.syncMarks();
        },

        undo(tile) {
            tile.marked = false;

            const position = displaced.indexOf(tile.id);

            if (position !== -1) {
                // Before this photo's ✕ the later ones never held the mark, so they lose their claim to it.
                displaced = displaced.slice(0, position);
                this.overview = tile.id;
            } else if (this.overview === null && this.isAvailable(tile)) {
                this.overview = tile.id;
            }

            this.syncMarks();
        },

        dismiss(tile) {
            revokePreview(tile);
            this.tiles = this.tiles.filter((other) => other.id !== tile.id);
        },

        add(event) {
            const files = Array.from(event.target.files);

            // Lets the same file be chosen again after a rejection.
            event.target.value = '';
            this.message = null;

            if (exceedsLimit(this.photoCount, files, maxPhotos)) {
                this.message = messages.tooMany.replace(':max', maxPhotos);

                return;
            }

            addTiles(this.tiles, files, messages, { marked: false }).forEach(({ tile, file }) => {
                queue = queue.then(() => this.upload(tile, file)).catch(() => rejectTile(tile, messages.uploadFailed));
            });
        },

        upload(tile, file) {
            return uploadTile(wire, `${statePath}.uploads.${tile.id}`, tile, file, messages, () => {
                if (this.overview === null) {
                    this.overview = tile.id;
                    this.syncMarks();
                }
            });
        },

        /**
         * Works without the server, whose answer may never come: this is the way out of an upload
         * whose request died. A tile that is queued or being shrunk has no Livewire upload yet.
         */
        cancel(tile) {
            if (!finishUpload(tile)) {
                return;
            }

            abandonedUploads.add(tile.id);
            revokePreview(tile);
            this.tiles = this.tiles.filter((other) => other.id !== tile.id);
            wire.$cancelUpload(`${statePath}.uploads.${tile.id}`);

            if (!destroyed) {
                this.syncMarks();
            }
        },

        /** Deferred on purpose: the marks travel with the next request, which is the save at the latest. */
        syncMarks() {
            wire.$set(`${statePath}.overview`, this.overview, false);
            wire.$set(
                `${statePath}.removed`,
                [...this.tiles.filter((tile) => tile.marked).map((tile) => tile.id), ...abandonedUploads],
                false,
            );
        },

        /** After a save that left the list as it was, the page keeps this component, and its marks must go. */
        reset() {
            this.tiles.filter((tile) => tile.uploading).forEach((tile) => this.cancel(tile));
            this.tiles.forEach(revokePreview);
            this.tiles = storedTiles(photos);
            displaced = [];
            this.overview = photos[0]?.path ?? null;
            this.message = null;
            this.syncMarks();
        },

        destroy() {
            destroyed = true;
            this.tiles.filter((tile) => tile.uploading).forEach((tile) => this.cancel(tile));
            this.tiles.forEach(revokePreview);
        },
    };
};

document.addEventListener('alpine:init', () => {
    registerUploadCount();
    window.Alpine.data('itemPhotos', itemPhotos);
});
