/*--- PENNY PINCHER: interakcje aplikacji /app/ ---*/

import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
  // Selecty z data-autosubmit wysyłają swój formularz po zmianie (np. przełącznik projektu, filtry).
  document.querySelectorAll('[data-autosubmit]').forEach((element) => {
    element.addEventListener('change', () => element.form?.requestSubmit());
  });

  // Formularze z data-confirm pytają przed wysłaniem.
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) {
        event.preventDefault();
      }
    });
  });

  // Blokada podwójnego wysłania formularzy wykonujących dłuższe operacje.
  document.querySelectorAll('form[data-busy]').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        if (button.dataset.busyLabel) {
          button.textContent = button.dataset.busyLabel;
        }
      });
    });
  });
});

Alpine.start();
