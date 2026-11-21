const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const MAX_BYTES = 12288 * 1024;
const MAX_SIDE = 2048;
const UPLOAD_COUNT = 'toryPhotoUploads';

const isAcceptable = (file) => ACCEPTED_TYPES.includes(file.type) && file.size <= MAX_BYTES;

const changeUploadCount = (change) => window.Alpine.store(UPLOAD_COUNT, window.Alpine.store(UPLOAD_COUNT) + change);

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

/** The number of running uploads, shared by every picker on the page; the save buttons wait for 0. */
export function registerUploadCount() {
    if (window.Alpine.store(UPLOAD_COUNT) === undefined) {
        window.Alpine.store(UPLOAD_COUNT, 0);
    }
}

/** Whether adding these files would take the picker over its limit; rejected files do not count. */
export const exceedsLimit = (photoCount, files, maxPhotos) => photoCount + files.filter(isAcceptable).length > maxPhotos;

/**
 * Adds a tile per file, a rejected file with its message, and counts the uploads that start.
 * Returns the reactive tiles that upload, each with its file.
 */
export function addTiles(tiles, files, extra = {}) {
    return files.flatMap((file) => {
        const acceptable = isAcceptable(file);

        tiles.push({
            id: crypto.randomUUID(),
            url: acceptable ? URL.createObjectURL(file) : null,
            name: file.name,
            uploading: acceptable,
            message: acceptable ? null : 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.',
            ...extra,
        });

        if (!acceptable) {
            return [];
        }

        changeUploadCount(1);

        // The reactive tile, not the plain object: changes to it must reach the page.
        return [{ tile: tiles[tiles.length - 1], file }];
    });
}

/** Returns false when the upload has already ended, so the count drops exactly once. */
export function finishUpload(tile) {
    if (!tile.uploading) {
        return false;
    }

    tile.uploading = false;
    changeUploadCount(-1);

    return true;
}

/** Frees a local preview; a stored photo's URL stays. */
export function revokePreview(tile) {
    if (tile.url?.startsWith('blob:')) {
        URL.revokeObjectURL(tile.url);
        tile.url = null;
    }
}

export function rejectTile(tile, message) {
    if (!finishUpload(tile)) {
        return;
    }

    revokePreview(tile);
    tile.message = message;
}

/**
 * Shrinks the file and uploads it to the property, unless the tile's upload ended meanwhile.
 * Resolves when Livewire is done, so uploads can run one after the other.
 */
export async function uploadTile(wire, property, tile, file, uploaded) {
    if (!tile.uploading) {
        return;
    }

    let fitted;

    try {
        fitted = await fitWithinLimit(file);
    } catch {
        rejectTile(tile, 'Dieses Bild lässt sich nicht lesen.');

        return;
    }

    if (!tile.uploading) {
        return;
    }

    await new Promise((resolve) => {
        wire.$upload(
            property,
            fitted,
            () => {
                if (finishUpload(tile)) {
                    uploaded();
                }

                resolve();
            },
            () => {
                rejectTile(tile, 'Hochladen fehlgeschlagen.');
                resolve();
            },
            () => {},
            resolve,
        );
    });
}
