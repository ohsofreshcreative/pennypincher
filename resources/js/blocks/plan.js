import Swiper from 'swiper';
import { Navigation } from 'swiper/modules';

import 'swiper/css';
import 'swiper/css/navigation';

const initPlan = () => {
  const sliders = document.querySelectorAll('.plan-swiper');
  if (!sliders.length) return;

  const desktop = window.matchMedia('(min-width: 48rem)');

  sliders.forEach((slider) => {
    const progressFill = slider.querySelector('.__progress-fill');
    let swiper = null;

    const fixOffset = (instance) => {
      const slideW = instance.slides[0]?.offsetWidth ?? 0;
      if (!slideW) return;
      const n = instance.slides.length;
      const gap = instance.params.spaceBetween;
      const currentMax = n * slideW + (n - 1) * gap - instance.width;
      const idealLast = (n - 1) * (slideW + gap);
      instance.params.slidesOffsetAfter = Math.max(0, idealLast - currentMax);
      instance.update();
    };

    const updateProgress = (instance) => {
      if (!progressFill) return;
      const pct = ((instance.activeIndex + 1) / instance.slides.length) * 100;
      progressFill.style.width = pct + '%';
    };

    const toggleSlider = () => {
      if (desktop.matches) {
        swiper?.destroy(true, true);
        swiper = null;
        return;
      }

      if (swiper) return;

      swiper = new Swiper(slider, {
        modules: [Navigation],
        loop: false,
        grabCursor: true,
        slidesPerView: 'auto',
        spaceBetween: 80,
        navigation: {
          nextEl: slider.querySelector('.__next'),
          prevEl: slider.querySelector('.__prev'),
        },
        on: {
          init(instance) {
            fixOffset(instance);
            updateProgress(instance);
          },
          slideChange: updateProgress,
          resize: fixOffset,
        },
      });
    };

    toggleSlider();
    desktop.addEventListener('change', toggleSlider);
  });
};

initPlan();
