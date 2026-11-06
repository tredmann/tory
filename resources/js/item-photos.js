import '../css/photo-picker.css';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const MAX_BYTES = 12288 * 1024;
const MAX_SIDE = 2048;
const MAX_PHOTOS = 6;

const isAcceptable = (file) => ACCEPTED_TYPES.includes(file.type) && file.size <= MAX_BYTES;

/**
 * Shrinks a photo to fit the limit, keeping its orientation and type. A GIF or a photo
 * within the limit stays the same file. Throws when the browser cannot read the photo.
 */
async function fitWithinLimit(file) {
    if (file.type === 'image/gif') {
        return file;
    }

    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

    try {
        const longestSide = Math.max(bitmap.width, bitmap.height);

        if (longestSide <= MAX_SIDE) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round((bitmap.width * MAX_SIDE) / longestSide));
        canvas.height = Math.max(1, Math.round((bitmap.height * MAX_SIDE) / longestSide));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, file.type, 0.9));

        if (!blob) {
            throw new Error('The photo could not be exported.');
        }

        // Livewire's upload reads the name, which a plain Blob does not have.
        return new File([blob], file.name, { type: blob.type });
    } finally {
        bitmap.close();
    }
}

const storedTiles = (photos) =>
    photos.map((photo) => ({
        id: photo.path,
        url: photo.url,
        name: null,
        marked: false,
        uploading: false,
        message: null,
    }));

const revokePreview = (tile) => {
    if (tile.url?.startsWith('blob:')) {
        URL.revokeObjectURL(tile.url);
    }
};

const changeUploadCount = (change) =>
    window.Alpine.store('toryPhotoUploads', window.Alpine.store('toryPhotoUploads') + change);

// A finish request already under way can still store a cancelled upload on the server, so its key stays marked.
const abandonedUploads = new Set();

const itemPhotos = ({ statePath, photos }) => {
    // Uploads run one after the other: the server keeps new photos in the order they finish.
    let queue = Promise.resolve();
    let wire;
    let destroyed = false;

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
            return this.photoCount < MAX_PHOTOS;
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

            this.overview = tile.id;
            this.syncMarks();
        },

        remove(tile) {
            tile.marked = true;

            if (this.overview === tile.id) {
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

            if (this.overview === null && this.isAvailable(tile)) {
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

            if (this.photoCount + files.filter(isAcceptable).length > MAX_PHOTOS) {
                this.message = 'Höchstens 6 Fotos.';

                return;
            }

            files.forEach((file) => {
                const tile = this.addTile(file);

                if (tile.uploading) {
                    changeUploadCount(1);
                    queue = queue.then(() => this.upload(tile, file));
                }
            });
        },

        addTile(file) {
            const acceptable = isAcceptable(file);

            this.tiles.push({
                id: crypto.randomUUID(),
                url: acceptable ? URL.createObjectURL(file) : null,
                name: file.name,
                marked: false,
                uploading: acceptable,
                message: acceptable ? null : 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.',
            });

            // The reactive tile, not the plain object: changes to it must reach the page.
            return this.tiles[this.tiles.length - 1];
        },

        async upload(tile, file) {
            if (!tile.uploading) {
                return;
            }

            let fitted;

            try {
                fitted = await fitWithinLimit(file);
            } catch {
                this.rejectTile(tile, 'Dieses Bild lässt sich nicht lesen.');

                return;
            }

            if (!tile.uploading) {
                return;
            }

            await new Promise((resolve) => {
                wire.$upload(
                    `${statePath}.uploads.${tile.id}`,
                    fitted,
                    () => {
                        if (this.finishUpload(tile) && this.overview === null) {
                            this.overview = tile.id;
                            this.syncMarks();
                        }

                        resolve();
                    },
                    () => {
                        this.rejectTile(tile, 'Hochladen fehlgeschlagen.');
                        resolve();
                    },
                    () => {},
                    resolve,
                );
            });
        },

        /**
         * Works without the server, whose answer may never come: this is the way out of an upload
         * whose request died. A tile that is queued or being shrunk has no Livewire upload yet.
         */
        cancel(tile) {
            if (!this.finishUpload(tile)) {
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

        rejectTile(tile, message) {
            if (!this.finishUpload(tile)) {
                return;
            }

            revokePreview(tile);
            tile.url = null;
            tile.message = message;
        },

        /** Returns false when the upload has already ended, so the counter drops exactly once. */
        finishUpload(tile) {
            if (!tile.uploading) {
                return false;
            }

            tile.uploading = false;
            changeUploadCount(-1);

            return true;
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
            this.tiles.forEach(revokePreview);
            this.tiles = storedTiles(photos);
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
    window.Alpine.store('toryPhotoUploads', 0);
    window.Alpine.data('itemPhotos', itemPhotos);
});
