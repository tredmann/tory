export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
export const MAX_BYTES = 12288 * 1024;
const MAX_SIDE = 2048;

export const isAcceptable = (file) => ACCEPTED_TYPES.includes(file.type) && file.size <= MAX_BYTES;

/**
 * Shrinks a photo to fit the limit, keeping its orientation and type. A GIF or a photo
 * within the limit stays the same file. Throws when the browser cannot read the photo.
 */
export async function fitWithinLimit(file) {
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
