import '../css/photo-picker.css';
import '../css/bulk-import.css';
import exifr from 'exifr';
import { fitWithinLimit, isAcceptable } from './photo-shrink.js';

const MAX_PHOTOS = 100;

const changeUploadCount = (change) =>
    window.Alpine.store('toryPhotoUploads', window.Alpine.store('toryPhotoUploads') + change);

/**
 * The capture time from EXIF, read from the original file because shrinking drops it,
 * otherwise the modification date the browser reports.
 */
async function takenAt(file) {
    try {
        const exif = await exifr.parse(file, ['DateTimeOriginal']);

        if (exif?.DateTimeOriginal instanceof Date && !Number.isNaN(exif.DateTimeOriginal.getTime())) {
            return exif.DateTimeOriginal.toISOString();
        }
    } catch {
        // No readable EXIF: fall back to the modification date.
    }

    return new Date(file.lastModified).toISOString();
}

/**
 * The server counts a file only while its key is in `meta`. A key gets there once its upload
 * finished and leaves with ✕, so an upload that lands after its cancellation stays unused.
 */
const bulkPhotos = ({ statePath }) => {
    // Uploads run one after the other, so a hundred photos never compete for the connection.
    let queue = Promise.resolve();
    let wire;
    const meta = {};

    return {
        tiles: [],
        message: null,

        init() {
            wire = this.$wire;
            wire.$set(`${statePath}.uploads`, {}, false);
            this.syncMeta();
        },

        get photoCount() {
            return this.tiles.filter((tile) => tile.message === null).length;
        },

        add(event) {
            const files = Array.from(event.target.files);

            // Lets the same file be chosen again after a rejection.
            event.target.value = '';
            this.message = null;

            if (this.photoCount + files.filter(isAcceptable).length > MAX_PHOTOS) {
                this.message = `Höchstens ${MAX_PHOTOS} Fotos pro Bulkimport.`;

                return;
            }

            files.forEach((file) => {
                const acceptable = isAcceptable(file);

                this.tiles.push({
                    id: crypto.randomUUID(),
                    url: acceptable ? URL.createObjectURL(file) : null,
                    name: file.name,
                    uploading: acceptable,
                    message: acceptable ? null : 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.',
                });

                if (acceptable) {
                    // The reactive tile, not the plain object: changes to it must reach the page.
                    const tile = this.tiles[this.tiles.length - 1];

                    changeUploadCount(1);
                    queue = queue.then(() => this.upload(tile, file)).catch(() => this.reject(tile, 'Hochladen fehlgeschlagen.'));
                }
            });
        },

        async upload(tile, file) {
            if (!tile.uploading) {
                return;
            }

            const time = await takenAt(file);
            let fitted;

            try {
                fitted = await fitWithinLimit(file);
            } catch {
                this.reject(tile, 'Dieses Bild lässt sich nicht lesen.');

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
                        if (this.finishUpload(tile)) {
                            meta[tile.id] = { name: file.name, taken_at: time };
                            this.syncMeta();
                        }

                        resolve();
                    },
                    () => {
                        this.reject(tile, 'Hochladen fehlgeschlagen.');
                        resolve();
                    },
                    () => {},
                    resolve,
                );
            });
        },

        /** Takes a photo out, whether it is queued, uploading, uploaded or rejected. */
        remove(tile) {
            if (this.finishUpload(tile)) {
                wire.$cancelUpload(`${statePath}.uploads.${tile.id}`);
            }

            if (tile.id in meta) {
                delete meta[tile.id];
                this.syncMeta();
            }

            this.revoke(tile);
            this.tiles = this.tiles.filter((other) => other.id !== tile.id);
            this.message = null;
        },

        reject(tile, message) {
            if (!this.finishUpload(tile)) {
                return;
            }

            this.revoke(tile);
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

        revoke(tile) {
            if (tile.url !== null) {
                URL.revokeObjectURL(tile.url);
                tile.url = null;
            }
        },

        /** Deferred on purpose: the list travels with the next request, which is "Gruppieren" at the latest. */
        syncMeta() {
            wire.$set(`${statePath}.meta`, { ...meta }, false);
        },

        destroy() {
            this.tiles.forEach((tile) => {
                if (this.finishUpload(tile)) {
                    wire.$cancelUpload(`${statePath}.uploads.${tile.id}`);
                }

                this.revoke(tile);
            });
        },
    };
};

document.addEventListener('alpine:init', () => {
    if (window.Alpine.store('toryPhotoUploads') === undefined) {
        window.Alpine.store('toryPhotoUploads', 0);
    }

    window.Alpine.data('bulkPhotos', bulkPhotos);
});
