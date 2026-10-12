import Swiper from 'swiper';
import { Pagination, Navigation } from 'swiper/modules';

import 'swiper/css';
import 'swiper/css/pagination';
import 'swiper/css/navigation';

const initSlider = () => {
  const sliders = document.querySelectorAll('.slider-standard');
  if (!sliders.length) return;

  sliders.forEach((slider) => {
    const progressFill = slider.querySelector('.__progress-fill');

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
      if (!progressFill) return;
      const n = swiper.slides.length;
      const pct = ((swiper.activeIndex + 1) / n) * 100;
      progressFill.style.width = pct + '%';
    };

    new Swiper(slider, {
      modules: [Pagination, Navigation],
      loop: false,
      grabCursor: true,
      slidesPerView: 'auto',
      spaceBetween: 80,
      navigation: {
        nextEl: slider.querySelector('.__next'),
        prevEl: slider.querySelector('.__prev'),
      },
      on: {
        init(swiper) {
          fixOffset(swiper);
          updateProgress(swiper);
        },
        slideChange: updateProgress,
        resize: fixOffset,
      },
    });
  });
};

initSlider();