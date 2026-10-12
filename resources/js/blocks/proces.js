import Swiper from 'swiper';
import { Navigation } from 'swiper/modules';

import 'swiper/css';
import 'swiper/css/navigation';

const initProces = () => {
  const sections = document.querySelectorAll('.b-proces');
  if (!sections.length) return;

  sections.forEach((section) => {
    const el = section.querySelector('.proces-swiper');
    if (!el) return;

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

initProces();
