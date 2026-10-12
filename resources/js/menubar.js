import $ from 'jquery';

$(document).ready(function () {
  'use strict';

  let c, currentScrollTop = 0;
  const navbar = $('.fixed-top');

  // Kliknięcie w link do kotwicy (np. podmenu) nie ma wysuwać menu — tylko naturalny
  // scroll użytkownika w górę. Podczas płynnego przewijania po kliknięciu tłumimy
  // pokazywanie/ukrywanie paska, dopóki scroll się nie ustabilizuje.
  let suppressed = false;
  let suppressTimer;

  const keepSuppressed = () => {
    window.clearTimeout(suppressTimer);
    suppressTimer = window.setTimeout(() => {
      suppressed = false;
    }, 150);
  };

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href*="#"]');
    if (!link) return;

    try {
      const url = new URL(link.href, window.location.href);
      if (url.origin === window.location.origin && url.pathname === window.location.pathname
        && url.search === window.location.search && url.hash) {
        suppressed = true;
        keepSuppressed();

        // Skok w dół do kotwicy: schowaj menu od razu, bo scroll jest wyciszony
        // i inaczej menu główne zostałoby nad przypiętym submenu.
        const target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
        if (target && target.getBoundingClientRect().top > 0) {
          navbar.addClass('scrollUp').removeClass('scrollTop scrollDown');
        }
      }
    } catch {
      // ignoruj nieprawidłowe URL-e
    }
  });

  // Ręczny scroll użytkownika kończy wyciszenie, inaczej menu nie wróci przy przewijaniu w górę.
  ['wheel', 'touchmove', 'keydown'].forEach((type) => {
    window.addEventListener(type, () => {
      window.clearTimeout(suppressTimer);
      suppressed = false;
    }, { passive: true });
  });

  $(window).on('scroll', function () {
    const a = $(window).scrollTop();
    const b = navbar.height();

    currentScrollTop = a;

    if (suppressed) {
      keepSuppressed();
    } else if (c < currentScrollTop && a > b) {
      navbar.addClass('scrollUp').removeClass('scrollTop');
    } else if (c > currentScrollTop && !(a <= b)) {
      navbar.removeClass('scrollUp').addClass('scrollDown').removeClass('scrollTop');
    } else if ($(document).scrollTop() < 500) {
      navbar.addClass('scrollTop').removeClass('scrollUp scrollDown');
    }

    c = currentScrollTop;
  });
});