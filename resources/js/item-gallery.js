// Listeners on the document survive Livewire re-renders of the item grid.
const galleryOf = (event) => event.target.closest?.('.tory-gallery');

// A mouse or pen over a strip of the photo shows that strip's photo; touch swipes natively.
document.addEventListener('pointermove', (event) => {
    const gallery = galleryOf(event);

    if (!gallery || event.pointerType === 'touch') {
        return;
    }

    const box = gallery.getBoundingClientRect();
    const count = gallery.querySelectorAll('img').length;
    const index = Math.min(count - 1, Math.floor(((event.clientX - box.left) / box.width) * count));

    // Every pointermove lands here, so the scroll position is only written when the photo changes.
    if (String(index) !== gallery.dataset.active) {
        gallery.scrollLeft = index * gallery.clientWidth;
    }
});

document.addEventListener('pointerout', (event) => {
    const gallery = galleryOf(event);

    if (!gallery || event.pointerType === 'touch' || gallery.contains(event.relatedTarget)) {
        return;
    }

    gallery.scrollLeft = 0;
});

// Scroll events do not bubble, so the capture phase sees them; the dots follow data-active.
document.addEventListener(
    'scroll',
    (event) => {
        const gallery = event.target;

        if (!(gallery instanceof Element) || !gallery.classList.contains('tory-gallery')) {
            return;
        }

        gallery.dataset.active = Math.round(gallery.scrollLeft / gallery.clientWidth);
    },
    true,
);
