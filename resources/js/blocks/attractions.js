document.querySelectorAll('.b-attractions .__open[aria-controls]').forEach((trigger) => {
  const popup = document.getElementById(trigger.getAttribute('aria-controls'));
  if (!popup) return;

  const closeButton = popup.querySelector('.__close');
  let previousOverflow;
  let startedOnBackdrop = false;

  trigger.addEventListener('click', () => {
    if (popup.open) return;
    previousOverflow = document.documentElement.style.overflow;
    popup.showModal();
    document.documentElement.style.overflow = 'hidden';
  });

  closeButton.addEventListener('click', () => popup.close());

  popup.addEventListener('pointerdown', (event) => {
    startedOnBackdrop = event.target === popup;
  });

  popup.addEventListener('click', (event) => {
    if (event.target === popup && startedOnBackdrop) popup.close();
  });

  // Natywny dialog obsługuje Escape i utrzymuje fokus wewnątrz popupu.
  popup.addEventListener('close', () => {
    document.documentElement.style.overflow = previousOverflow;
    trigger.focus({ preventScroll: true });
  });
});
