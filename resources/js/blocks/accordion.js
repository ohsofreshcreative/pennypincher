/**
 * Side Modal Handler
 */
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('side-modal');
  const overlay = document.getElementById('side-modal-overlay');
  const closeBtn = document.getElementById('modal-close-btn');
  const openTriggers = document.querySelectorAll('.js-open-modal');
  const body = document.body;

  // Upewnij się, że wszystkie elementy istnieją
  if (!modal || !overlay || !closeBtn || openTriggers.length === 0) {
    return;
  }

  // NOWY KOD: Znajdź ukryte pole w modalu
  const hiddenJobTitleInput = modal.querySelector('input[name="job-title"]');

  const openModal = (jobTitle = '') => {
    // NOWY KOD: Ustaw wartość ukrytego pola, jeśli istnieje
    if (hiddenJobTitleInput) {
      hiddenJobTitleInput.value = jobTitle;
    }

    overlay.classList.remove('hidden');
    overlay.classList.add('opacity-100');
    modal.classList.remove('translate-x-full');
    body.style.overflow = 'hidden';
  };

  const closeModal = () => {
    overlay.classList.add('hidden');
    overlay.classList.remove('opacity-100');
    modal.classList.add('translate-x-full');
    body.style.overflow = '';
  };

  // Otwieranie modala
  openTriggers.forEach(trigger => {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      // NOWY KOD: Odczytaj tytuł stanowiska z atrybutu data-
      const jobTitle = trigger.dataset.jobTitle || 'Nie określono';
      openModal(jobTitle);
    });
  });

  // Zamykanie modala
  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', closeModal);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeModal();
    }
  });
});