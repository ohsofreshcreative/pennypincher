const { gsap, ScrollTrigger } = window;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const desktop = window.matchMedia('(min-width: 1024px)');

if (gsap && ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

document.querySelectorAll('.b-possibilities').forEach((section) => {
  const scene = section.querySelector('.__scene');
  const content = section.querySelector('.__content');
  const list = section.querySelector('.__list');
  const media = section.querySelector('.__media');
  const cards = [...section.querySelectorAll('.__point')];
  const buttons = cards.map((card) => card.querySelector('.__trigger'));
  const images = [...section.querySelectorAll('[data-possibilities-image]')];
  if (!scene || !list || !buttons.length) return;

  const originalMinHeight = section.style.minHeight;
  let active = 0;
  let scrollIndex = null;
  let trigger;
  let pinned = false;
  let resizeTimer;

  const select = (index, animate = true) => {
    if (index === active) return;
    active = index;

    cards.forEach((card, position) => {
      card.dataset.active = String(position === index);
      buttons[position].setAttribute('aria-pressed', String(position === index));
    });
    images.forEach((image, position) => {
      image.setAttribute('aria-hidden', String(position !== index));
    });

    if (!images.length) return;

    if (gsap && animate && !reducedMotion.matches) {
      gsap.to(images, {
        opacity: (position) => position === index ? 1 : 0,
        duration: 0.35,
        ease: 'power1.out',
        overwrite: true,
      });
    } else {
      if (gsap) gsap.killTweensOf(images);
      images.forEach((image, position) => {
        image.style.opacity = position === index ? '1' : '0';
      });
    }
  };

  // Kliknięcie zostaje aktywne aż do wejścia w kolejny etap przewijania.
  const selectFromScroll = (index) => {
    if (index === scrollIndex) return;
    scrollIndex = index;
    select(index);
  };

  buttons.forEach((button, index) => {
    button.addEventListener('click', () => {
      select(index);

      if (pinned && trigger?.isActive) {
        const progress = (index + 0.5) / cards.length;
        const top = trigger.start + (trigger.end - trigger.start) * progress;
        scrollIndex = index;
        window.scrollTo({ top, behavior: 'instant' });
        ScrollTrigger.update();
      }
    });
  });

  if (!gsap || !ScrollTrigger || cards.length < 2) return;

  const headerOffset = () => (parseFloat(getComputedStyle(document.documentElement)
    .getPropertyValue('--menu-height')) || 0) + 24;

  const pinOffset = () => headerOffset()
    - (parseFloat(getComputedStyle(section).paddingTop) || 0);

  const activationLine = () => {
    if (!desktop.matches && media) {
      return Math.min(window.innerHeight * 0.75, media.getBoundingClientRect().bottom + 24);
    }
    return window.innerHeight * 0.5;
  };

  // Poniżej tej wysokości okna treść i tak by się nie zmieściła bez ucinania kafelków —
  // wyłączamy przełączanie scrollem, zostaje tylko zmiana zdjęcia klikiem w kafelek.
  const MIN_HEIGHT_FOR_SCROLL = 700;

  const buildScroll = () => {
    trigger?.kill();
    trigger = null;
    section.style.minHeight = originalMinHeight;
    gsap.killTweensOf(images);
    images.forEach((image, position) => {
      image.style.opacity = position === active ? '1' : '0';
    });
    if (media) {
      ['height', 'position', 'top', 'align-self'].forEach((property) => media.style.removeProperty(property));
    }

    scrollIndex = null;

    if (window.innerHeight < MIN_HEIGHT_FOR_SCROLL) {
      pinned = false;
      return;
    }

    const availableHeight = window.innerHeight - headerOffset() - 24;
    // Wysokość okna >= MIN_HEIGHT_FOR_SCROLL wystarczy do pinowania — sekcja jest
    // wyśrodkowana w pionie (lg:justify-center), więc ewentualna nadwyżka treści
    // rozkłada się równo góra/dół zamiast być ucinana tylko na dole.
    pinned = desktop.matches && !reducedMotion.matches && images.length > 0;

    if (pinned) {
      // Tło przypiętej sekcji musi sięgać do dołu okna także przy ujemnym przesunięciu.
      section.style.minHeight = `${Math.ceil(window.innerHeight - pinOffset())}px`;

      trigger = ScrollTrigger.create({
        // Przypinamy również tło; padding sekcji nie przesuwa treści pod nagłówek.
        trigger: section,
        pin: section,
        pinSpacing: true,
        start: () => `top ${pinOffset()}`,
        end: () => `+=${cards.length * Math.max(window.innerHeight * 0.6, 320)}`,
        anticipatePin: 1,
        invalidateOnRefresh: true,
        onUpdate: (self) => selectFromScroll(Math.min(cards.length - 1, Math.floor(self.progress * cards.length))),
        onRefresh: (self) => selectFromScroll(Math.min(cards.length - 1, Math.floor(self.progress * cards.length))),
      });
    } else {
      // Wysoka treść i telefon korzystają z naturalnego przewijania oraz przyklejonego zdjęcia.
      if (desktop.matches && media) {
        media.style.height = `${Math.max(1, Math.min(content.offsetHeight, availableHeight))}px`;
        media.style.position = 'sticky';
        media.style.top = `${headerOffset()}px`;
        media.style.alignSelf = 'start';
      }

      const update = (self) => {
        const line = activationLine();
        let index = 0;
        cards.forEach((card, position) => {
          if (card.getBoundingClientRect().top <= line) index = position;
        });
        if (self.progress === 1) index = cards.length - 1;
        selectFromScroll(index);
      };

      trigger = ScrollTrigger.create({
        trigger: list,
        start: () => `clamp(top ${activationLine()})`,
        end: () => `clamp(bottom ${activationLine()})`,
        invalidateOnRefresh: true,
        onUpdate: update,
        onRefresh: update,
      });
    }
  };

  const scheduleRebuild = () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      buildScroll();
      ScrollTrigger.refresh();
    }, 150);
  };

  buildScroll();
  document.fonts.ready.then(scheduleRebuild);
  window.addEventListener('resize', scheduleRebuild);
  window.addEventListener('load', scheduleRebuild);
  reducedMotion.addEventListener('change', scheduleRebuild);
});
