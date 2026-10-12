document.querySelectorAll('.b-submenu').forEach((root) => {
  const menu = root.querySelector('.__menu');
  if (!menu) return;

  const items = [...menu.querySelectorAll('a[href]')].flatMap((link) => {
    try {
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.pathname !== window.location.pathname
        || url.search !== window.location.search || !url.hash
        || (link.target && link.target !== '_self')) return [];

      const id = decodeURIComponent(url.hash.slice(1));
      return [{ link, id, target: document.getElementById(id) }];
    } catch {
      return [];
    }
  });
  if (!items.length) return;

  const menuOffset = () => parseFloat(getComputedStyle(document.documentElement)
    .getPropertyValue('--menu-height')) || 0;

  // Przypięty pasek podmenu chowa górę docelowej sekcji przy skoku po kotwicy —
  // odsuwamy punkt lądowania o jego własną wysokość.
  const updateAnchorOffsets = () => {
    const height = root.getBoundingClientRect().height;
    items.forEach((item) => {
      if (item.target) item.target.style.scrollMarginTop = `${height}px`;
    });
  };
  updateAnchorOffsets();
  window.addEventListener('resize', updateAnchorOffsets);
  document.fonts.ready.then(updateAnchorOffsets);

  // Podmenu jest przypięte od momentu wejścia w viewport aż do końca ostatniej
  // sekcji, do której prowadzi link w menu — potem przewija się normalnie.
  const lastAnchor = [...items].reverse().find((item) => item.target);
  let placeholder;
  let stuck = false;

  const updateSticky = () => {
    if (!lastAnchor) return;

    const offset = menuOffset();
    const naturalTop = (stuck ? placeholder : root).getBoundingClientRect().top + window.scrollY;
    const endY = lastAnchor.target.getBoundingClientRect().bottom + window.scrollY;
    const scrollPos = window.scrollY + offset;
    const shouldStick = scrollPos >= naturalTop && scrollPos < endY;

    if (shouldStick && !stuck) {
      const rect = root.getBoundingClientRect();
      placeholder.style.height = `${rect.height}px`;
      root.style.position = 'fixed';
      root.style.top = '0px';
      root.style.left = `${rect.left}px`;
      root.style.width = `${rect.width}px`;
      stuck = true;
    } else if (!shouldStick && stuck) {
      root.style.removeProperty('position');
      root.style.removeProperty('top');
      root.style.removeProperty('left');
      root.style.removeProperty('width');
      placeholder.style.removeProperty('height');
      stuck = false;
    } else if (stuck) {
      // Szerokość/pozycja mogą się zmienić przy resize — trzymaj przypięty pasek w zgodzie z layoutem.
      const rect = placeholder.getBoundingClientRect();
      root.style.left = `${rect.left}px`;
      root.style.width = `${rect.width}px`;
    }
  };

  if (lastAnchor) {
    placeholder = document.createElement('div');
    placeholder.setAttribute('aria-hidden', 'true');
    root.after(placeholder);
  }

  let active;
  let navigating = false;
  let navigationTimer;
  let frame;

  const updateIndicator = () => {
    if (!active) return;

    const rootRect = root.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const linkRect = active.link.getBoundingClientRect();
    const left = Math.max(linkRect.left, menuRect.left, rootRect.left);
    const right = Math.min(linkRect.right, menuRect.right, rootRect.right);

    root.style.setProperty('--submenu-left', `${left - rootRect.left}px`);
    root.style.setProperty('--submenu-width', `${Math.max(0, right - left)}px`);
  };

  const setActive = (item) => {
    if (active !== item) {
      active = item;
      items.forEach(({ link }) => {
        link.classList.toggle('active', link === item.link);
        if (link === item.link) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    }
    updateIndicator();
  };

  const updateFromScroll = () => {
    frame = null;
    if (navigating) {
      updateIndicator();
      return;
    }

    const offset = menuOffset();
    const atBottom = window.scrollY > 0 && window.scrollY + window.innerHeight
      >= document.documentElement.scrollHeight - 1;
    let current = items[0];
    let closestTop = -Infinity;

    items.forEach((item) => {
      if (!item.target) return;
      const top = item.target.getBoundingClientRect().top;
      if ((top <= offset + 1 || atBottom) && top >= closestTop) {
        current = item;
        closestTop = top;
      }
    });
    setActive(current);
  };

  const scheduleUpdate = () => {
    if (frame == null) frame = window.requestAnimationFrame(updateFromScroll);
  };

  // Zachowaj klikniętą pozycję podczas płynnego przewijania między sekcjami.
  const waitForScroll = () => {
    window.clearTimeout(navigationTimer);
    navigationTimer = window.setTimeout(() => {
      navigating = false;
      scheduleUpdate();
    }, 150);
  };

  const activateNavigation = (item) => {
    navigating = true;
    setActive(item);
    waitForScroll();
  };

  const updateFromHash = () => {
    const item = items.find(({ link }) => new URL(link.href).hash === window.location.hash);
    if (item) activateNavigation(item);
    else {
      navigating = false;
      scheduleUpdate();
    }
  };

  items.forEach((item) => {
    item.link.addEventListener('click', (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey
        || event.ctrlKey || event.shiftKey || event.altKey) return;
      activateNavigation(item);
    });
  });

  window.addEventListener('scroll', () => {
    if (navigating) waitForScroll();
    scheduleUpdate();
    updateSticky();
  }, { passive: true });
  menu.addEventListener('scroll', updateIndicator, { passive: true });
  window.addEventListener('hashchange', updateFromHash);
  window.addEventListener('resize', () => {
    scheduleUpdate();
    updateSticky();
  });
  window.addEventListener('load', () => {
    scheduleUpdate();
    updateSticky();
  });
  document.fonts.ready.then(() => {
    scheduleUpdate();
    updateSticky();
  });

  updateFromScroll();
  updateFromHash();
  updateSticky();
});
