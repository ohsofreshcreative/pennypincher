
document.addEventListener("DOMContentLoaded", function() {
  var widgetTitles = document.querySelectorAll('.widget-title');
  widgetTitles.forEach(function(widgetTitle) {
    widgetTitle.addEventListener("click", function() {
      var widgetContainer = widgetTitle.parentElement; // Pobierz rodzica nagłówka
      var widgetMenu = widgetContainer.querySelector('.menu'); // Zlokalizuj menu w obrębie rodzica
      
      // Sprawdź, czy bieżące menu jest otwarte
      var isOpen = widgetContainer.classList.contains('open');
      
      // Zamknij wszystkie menu, jeśli bieżące menu nie jest otwarte
      if (!isOpen) {
        closeAllMenus();
      }
      
      // Otwórz/zamknij bieżące menu
      widgetContainer.classList.toggle('open');
      if (widgetContainer.classList.contains('open')) {
        widgetMenu.style.maxHeight = widgetMenu.scrollHeight + "px";
      } else {
        widgetMenu.style.maxHeight = null;
      }
    });
  });
  
  function closeAllMenus() {
    var openMenus = document.querySelectorAll('.footer_widget.open');
    openMenus.forEach(function(menu) {
      var menuContent = menu.querySelector('.menu');
      menu.classList.remove('open');
      menuContent.style.maxHeight = null;
    });
  }
});