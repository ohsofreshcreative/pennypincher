import Swiper from 'swiper';
import { Navigation } from 'swiper/modules';

import 'swiper/css';
import 'swiper/css/navigation';

const initOffer = () => {
  const sections = document.querySelectorAll('.b-offer');
  if (!sections.length) return;

  sections.forEach((section) => {
    const el = section.querySelector('.offer-swiper');
    if (!el) return;

    // Strzałki leżą poza kontenerem .swiper, więc szukamy ich w obrębie sekcji.
    new Swiper(el, {
      modules: [Navigation],
      loop: false,
      grabCursor: true,
      slidesPerView: 'auto',
      spaceBetween: 24,
      navigation: {
        prevEl: section.querySelector('.__prev'),
        nextEl: section.querySelector('.__next'),
      },
    });
  });
};

initOffer();
