import Swiper from 'swiper';
// Zaimportuj potrzebne moduły JS
import { Navigation } from 'swiper/modules';

document.addEventListener('DOMContentLoaded', () => {
  const swipers = document.querySelectorAll('.posts-slider');

  if (swipers.length > 0) {
    swipers.forEach((container) => {
      const swiper = new Swiper(container, {
        modules: [Navigation],
        loop: true,

        slidesPerView: 'auto', 
        spaceBetween: 0,
        freeMode: {
          enabled: true,
          sticky: false, 
          momentum: false,
        },

        navigation: {
          nextEl: ".__next",
          prevEl: ".__prev",
        },
        breakpoints: {
          0: { slidesPerView: 1, spaceBetween: 20 },
          768: { slidesPerView: 1, spaceBetween: 30 },
          1024: { slidesPerView: 2, spaceBetween: 32 },
        },
        on: {
          init: function () {
            updateFirstVisibleSlide(this, container);
          },
          slideChange: function () {
            updateFirstVisibleSlide(this, container);
          },
        },
      });

      // Helper function to update first visible slide
      function updateFirstVisibleSlide(swiperInstance, swiperContainer) {
        const allSlides = swiperContainer.querySelectorAll('.swiper-slide');
        allSlides.forEach((slide) => {
          slide.classList.remove('first-visible-slide');
        });

        if (swiperInstance.slides[swiperInstance.activeIndex]) {
          swiperInstance.slides[swiperInstance.activeIndex].classList.add(
            'first-visible-slide'
          );
        }
      }
    });
  }
});