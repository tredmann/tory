import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';
import '../css/slideshow.css';

const lightbox = GLightbox({ loop: true });

function showPosition() {
    const position = document.querySelector('.tory-slideshow-position');
    const total = lightbox.elements.length;

    position.textContent = total > 1 ? `${lightbox.getActiveSlideIndex() + 1} / ${total}` : '';
}

lightbox.on('open', () => {
    const position = document.createElement('div');
    position.className = 'tory-slideshow-position';
    position.setAttribute('aria-live', 'polite');
    document.querySelector('.glightbox-container .gcontainer').append(position);

    showPosition();
});

lightbox.on('slide_changed', showPosition);

function openAt(image) {
    const urls = JSON.parse(image.dataset.slideshow);

    // GLightbox ignores its global zoomable setting for slides set from code.
    lightbox.setElements(urls.map((href) => ({ href, type: 'image', zoomable: false })));
    lightbox.open(null, Number(image.dataset.slideshowIndex));
}

// Capture phase: this listener survives Livewire re-renders and runs before the row link around the image.
document.addEventListener(
    'click',
    (event) => {
        const image = event.target.closest?.('img[data-slideshow]');

        if (!image) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openAt(image);
    },
    true,
);

// Only view page photos are focusable; a list photo's row link leads to the detail page instead.
document.addEventListener('keydown', (event) => {
    const image = event.target.closest?.('img[data-slideshow][tabindex]');

    if (!image || (event.key !== 'Enter' && event.key !== ' ')) {
        return;
    }

    // Space would scroll the page.
    event.preventDefault();
    openAt(image);
});
