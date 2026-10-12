const initOverlap = () => {
  const { gsap, ScrollTrigger } = window;
  if (!gsap || !ScrollTrigger) return;

  gsap.registerPlugin(ScrollTrigger);

  gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', () => {
    document.querySelectorAll('.b-overlap .__stack').forEach((stack) => {
      const wrappers = [...stack.children];

      wrappers.slice(0, -1).forEach((wrapper, index) => {
        const card = wrapper.querySelector('.__card');

        gsap.fromTo(card, {
          scale: 1,
          filter: 'brightness(1) blur(0px)',
        }, {
          scale: 0.92,
          filter: 'brightness(1.25) blur(4px)',
          transformOrigin: 'center top',
          ease: 'none',
          scrollTrigger: {
            // Mierzymy nieruchomy kontener, bo pozycja kafelków zmienia się przez sticky.
            trigger: stack,
            start: () => {
              const styles = getComputedStyle(wrapper);
              const offset = index * wrapper.offsetHeight + parseFloat(styles.paddingTop);

              return `top+=${offset} ${parseFloat(styles.top)}`;
            },
            end: () => {
              const offset = (index + 1) * wrapper.offsetHeight;

              return `top+=${offset} ${parseFloat(getComputedStyle(wrapper).top)}`;
            },
            scrub: true,
            invalidateOnRefresh: true,
          },
        });
      });
    });
  });

  document.fonts.ready.then(() => ScrollTrigger.refresh());
};

initOverlap();
