import '../css/photo-picker.css';
import '../css/bulk-import.css';
import exifr from 'exifr';
import { addTiles, exceedsLimit, finishUpload, registerUploadCount, rejectTile, revokePreview, uploadTile } from './photo-upload.js';

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
const bulkPhotos = ({ statePath, maxPhotos }) => {
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

            if (exceedsLimit(this.photoCount, files, maxPhotos)) {
                this.message = `Höchstens ${maxPhotos} Fotos pro Bulkimport.`;

                return;
            }

            addTiles(this.tiles, files).forEach(({ tile, file }) => {
                queue = queue.then(() => this.upload(tile, file)).catch(() => rejectTile(tile, 'Hochladen fehlgeschlagen.'));
            });
        },

        async upload(tile, file) {
            if (!tile.uploading) {
                return;
            }

            const time = await takenAt(file);

            await uploadTile(wire, `${statePath}.uploads.${tile.id}`, tile, file, () => {
                meta[tile.id] = { name: file.name, taken_at: time };
                this.syncMeta();
            });
        },

        /** Takes a photo out, whether it is queued, uploading, uploaded or rejected. */
        remove(tile) {
            if (finishUpload(tile)) {
                wire.$cancelUpload(`${statePath}.uploads.${tile.id}`);
            }

            if (tile.id in meta) {
                delete meta[tile.id];
                this.syncMeta();
            }

            revokePreview(tile);
            this.tiles = this.tiles.filter((other) => other.id !== tile.id);
            this.message = null;
        },

        /** Deferred on purpose: the list travels with the next request, which is "Gruppieren" at the latest. */
        syncMeta() {
            wire.$set(`${statePath}.meta`, { ...meta }, false);
        },

        destroy() {
            this.tiles.forEach((tile) => {
                if (finishUpload(tile)) {
                    wire.$cancelUpload(`${statePath}.uploads.${tile.id}`);
                }

                revokePreview(tile);
            });
        },
    };
};

document.addEventListener('alpine:init', () => {
    registerUploadCount();
    window.Alpine.data('bulkPhotos', bulkPhotos);
});
