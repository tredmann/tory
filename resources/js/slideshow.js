import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';
import '../css/slideshow.css';

const lightbox = GLightbox({ loop: true, zoomable: false });

function showPosition() {
    const position = document.querySelector('.tory-slideshow-position');
    const total = lightbox.elements.length;

    position.textContent = total > 1 ? `${lightbox.getActiveSlideIndex() + 1} / ${total}` : '';
}

lightbox.on('open', () => {
    const position = document.createElement('div');
    position.className = 'tory-slideshow-position';
    document.querySelector('.glightbox-container .gcontainer').append(position);

    showPosition();
});

lightbox.on('slide_changed', showPosition);

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

        const urls = JSON.parse(image.dataset.slideshow);

        lightbox.setElements(urls.map((href) => ({ href, type: 'image' })));
        lightbox.open(null, Number(image.dataset.slideshowIndex));
    },
    true,
);
