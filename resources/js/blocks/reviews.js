import Swiper from 'swiper';
import { Navigation, Pagination } from 'swiper/modules';

import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/pagination';

/**
 * Logika dla przycisków "Zobacz całość" i obsługi popupa z recenzją.
 * @param {HTMLElement} scope - Element, w którym szukamy recenzji.
 */
const initReviewPopup = (scope = document) => {
  const reviewCards = scope.querySelectorAll('.swiper-slide .__card');
  const popup = document.getElementById('review-popup');
  const popupText = document.getElementById('review-popup-text');
  const popupAuthor = document.getElementById('review-popup-author');
  const closeButton = popup?.querySelector('.review-popup__close');

  if (!popup || !reviewCards.length) return;

  reviewCards.forEach(card => {
    const textElement = card.querySelector('.__txt');
    const moreButton = card.querySelector('.btn-more');
    const authorElement = card.querySelector('.font-header');

    if (!textElement || !moreButton || !authorElement) return;

    // Pokaż przycisk "Zobacz całość", jeśli tekst jest obcięty
    // Używamy setTimeout, aby dać czas na renderowanie
    setTimeout(() => {
      if (textElement.scrollHeight > textElement.clientHeight) {
        moreButton.classList.remove('hidden');
      }
    }, 150);

    // Po kliknięciu "Zobacz całość"
    moreButton.addEventListener('click', () => {
      // Wypełnij popup danymi z klikniętej karty
      popupText.innerHTML = textElement.innerHTML;
      popupAuthor.textContent = authorElement.textContent;
      // Pokaż popup
      popup.classList.remove('hidden');
      setTimeout(() => popup.classList.add('is-visible'), 10); // Opóźnienie dla animacji
      document.body.style.overflow = 'hidden'; // Zablokuj scrollowanie tła
    });
  });

  // Funkcja zamykająca popup
  const closePopup = () => {
    popup.classList.remove('is-visible');
    document.body.style.overflow = ''; // Odblokuj scrollowanie
    // Ukryj popup po zakończeniu animacji
    setTimeout(() => popup.classList.add('hidden'), 300);
  };

  // Zamykanie popupa
  closeButton?.addEventListener('click', closePopup);
  // Zamykanie po kliknięciu w tło
  popup.addEventListener('click', (e) => {
    if (e.target === popup) {
      closePopup();
    }
  });
  // Zamykanie klawiszem Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && popup.classList.contains('is-visible')) {
      closePopup();
    }
  });
};

/**
 * Inicjalizacja karuzeli Swiper.
 * @param {HTMLElement} scope - Element, w którym szukamy karuzeli.
 */
const initReviewsSwiper = (scope = document) => {
  const swiperElements = scope.querySelectorAll('.reviews-swiper:not(.swiper-initialized)');
  if (!swiperElements.length) return;

  swiperElements.forEach((swiperEl) => {
    const progressFill = swiperEl.querySelector('.__progress-fill');

    const fixOffset = (swiper) => {
      const slideW = swiper.slides[0]?.offsetWidth ?? 0;
      if (!slideW) return;
      const n = swiper.slides.length;
      const gap = swiper.params.spaceBetween;
      const currentMax = n * slideW + (n - 1) * gap - swiper.width;
      const idealLast = (n - 1) * (slideW + gap);
      swiper.params.slidesOffsetAfter = Math.max(0, idealLast - currentMax);
      swiper.update();
    };

    const updateProgress = (swiper) => {
      if (progressFill) {
        const pct = swiper.isEnd
          ? 100
          : ((swiper.activeIndex + 1) / swiper.slides.length * 100);
        progressFill.style.width = pct + '%';
      }
    };

    // ... (reszta kodu Swipera pozostaje bez zmian)
    new Swiper(swiperEl, {
      modules: [Navigation, Pagination],
      slidesPerView: 1.2,
      spaceBetween: 24,
      loop: false,
      pagination: { el: swiperEl.querySelector('.swiper-pagination'), clickable: true },
      navigation: { nextEl: swiperEl.querySelector('.__next'), prevEl: swiperEl.querySelector('.__prev') },
      breakpoints: {
        768: { slidesPerView: 2.5, spaceBetween: 24 },
        1024: { slidesPerView: 2.5, spaceBetween: 24 },
      },
      on: {
        init(swiper) { fixOffset(swiper); updateProgress(swiper); initReviewPopup(swiperEl); },
        slideChange(swiper) { updateProgress(swiper); initReviewPopup(swiperEl); },
        resize: fixOffset,
      },
    });
  });
};

// Inicjalizacja na starcie
initReviewsSwiper();
initReviewPopup();

// Wsparcie dla edytora ACF
if (window.acf) {
  window.acf.addAction('render_block', (el) => {
    const node = el?.[0] ?? el;
    if (node) {
      initReviewsSwiper(node);
      initReviewPopup(node);
    }
  });
}

export default initReviewsSwiper;