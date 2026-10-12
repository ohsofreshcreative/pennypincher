/**
 * Catalogues Filter Module
 * Filtrowanie katalogów PDF z custom multi-select dropdownami
 */

class CataloguesFilter {
  constructor() {
    console.log('🔍 CataloguesFilter constructor called');
    
    this.grid = document.querySelector('[data-catalogue-grid]');
    console.log('Grid found:', this.grid);
    
    if (!this.grid) {
      console.warn('⚠️ No grid found! Stopping initialization.');
      return;
    }

    this.searchInput = document.querySelector('[data-catalogue-search]');
    this.filterCheckboxes = document.querySelectorAll('[data-catalogue-filter]');
    this.resetBtn = document.querySelector('[data-catalogue-reset]');
    this.items = document.querySelectorAll('.catalogue-item');
    this.noResults = document.querySelector('[data-catalogue-no-results]');
    this.countElement = document.querySelector('[data-catalogue-count]');
    this.multiselects = document.querySelectorAll('[data-multiselect]');
    this.dropdownSearches = document.querySelectorAll('[data-dropdown-search]');
    this.totalCount = this.items.length;

    this.filters = {
      search: '',
      producer: [],
      group: [],
      industry: []
    };

    this.init();
  }

  init() {
    console.log('🎬 Initializing CataloguesFilter...');
    this.initMultiselects();
    this.initDropdownSearches();
    this.attachEventListeners();
    console.log('✅ CataloguesFilter initialized');
  }

  initMultiselects() {
    console.log('🎯 Initializing multiselects...');
    
    this.multiselects.forEach((multiselect, index) => {
      const trigger = multiselect.querySelector('.multiselect-trigger');
      const dropdown = multiselect.querySelector('.multiselect-dropdown');

      if (!trigger || !dropdown) {
        console.warn(`⚠️ Missing trigger or dropdown in multiselect ${index}`);
        return;
      }

      trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        this.closeAllDropdowns(multiselect);
        dropdown.classList.toggle('hidden');
        
        // Focus na search input w dropdownie po otwarciu
        if (!dropdown.classList.contains('hidden')) {
          const searchInput = dropdown.querySelector('[data-dropdown-search]');
          if (searchInput) {
            setTimeout(() => searchInput.focus(), 100);
          }
        }
      });
    });

    // Zamknij dropdown po kliknięciu poza nim
    document.addEventListener('click', (e) => {
      if (!e.target.closest('[data-multiselect]')) {
        this.closeAllDropdowns();
      }
    });
  }

  initDropdownSearches() {
    console.log('🔎 Initializing dropdown searches...');
    
    this.dropdownSearches.forEach(searchInput => {
      const filterType = searchInput.dataset.dropdownSearch;
      const container = document.querySelector(`[data-options-container="${filterType}"]`);
      
      if (!container) return;
      
      searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        const options = container.querySelectorAll('.filter-option');
        
        options.forEach(option => {
          const value = option.dataset.value || '';
          const text = option.textContent.toLowerCase();
          
          if (text.includes(searchTerm) || value.includes(searchTerm)) {
            option.style.display = '';
          } else {
            option.style.display = 'none';
          }
        });
      });
      
      // Prevent dropdown close on input click
      searchInput.addEventListener('click', (e) => {
        e.stopPropagation();
      });
    });
  }

  closeAllDropdowns(except = null) {
    this.multiselects.forEach(multiselect => {
      if (multiselect === except) return;
      const dropdown = multiselect.querySelector('.multiselect-dropdown');
      if (dropdown) {
        dropdown.classList.add('hidden');
        
        // Reset search input w dropdownie
        const searchInput = dropdown.querySelector('[data-dropdown-search]');
        if (searchInput) {
          searchInput.value = '';
          // Pokaż wszystkie opcje
          const container = searchInput.closest('.multiselect-dropdown').querySelector('[data-options-container]');
          if (container) {
            container.querySelectorAll('.filter-option').forEach(opt => {
              opt.style.display = '';
            });
          }
        }
      }
    });
  }

  attachEventListeners() {
    console.log('🎧 Attaching event listeners...');
    
    // Wyszukiwanie globalne
    if (this.searchInput) {
      this.searchInput.addEventListener('input', (e) => {
        this.filters.search = e.target.value.toLowerCase();
        this.filterItems();
      });
    }

    // Checkboxy w dropdownach
    this.filterCheckboxes.forEach(checkbox => {
      checkbox.addEventListener('change', (e) => {
        const filterType = e.target.dataset.catalogueFilter;
        const value = e.target.value;

        if (e.target.checked) {
          if (!this.filters[filterType].includes(value)) {
            this.filters[filterType].push(value);
          }
        } else {
          this.filters[filterType] = this.filters[filterType].filter(v => v !== value);
        }

        this.updateCounts();
        this.filterItems();
      });
    });

    // Reset
    if (this.resetBtn) {
      this.resetBtn.addEventListener('click', () => {
        this.resetFilters();
      });
    }
  }

  filterItems() {
    let visibleCount = 0;

    this.items.forEach(item => {
      const title = item.dataset.title || '';
      const producer = item.dataset.producer || '';
      const group = item.dataset.group || '';
      const industry = item.dataset.industry || '';

      let show = true;

      if (this.filters.search && !title.includes(this.filters.search)) {
        show = false;
      }

      if (this.filters.producer.length > 0 && !this.filters.producer.includes(producer)) {
        show = false;
      }

      if (this.filters.group.length > 0 && !this.filters.group.includes(group)) {
        show = false;
      }

      if (this.filters.industry.length > 0 && !this.filters.industry.includes(industry)) {
        show = false;
      }

      if (show) {
        item.style.display = '';
        visibleCount++;
      } else {
        item.style.display = 'none';
      }
    });

    this.updateResultCount(visibleCount);
    this.toggleNoResults(visibleCount === 0);
  }

  updateCounts() {
    const counts = {
      producer: this.filters.producer.length,
      group: this.filters.group.length,
      industry: this.filters.industry.length
    };

    Object.keys(counts).forEach(key => {
      const countEl = document.querySelector(`[data-count="${key}"]`);
      if (countEl) {
        countEl.textContent = counts[key];
      }
    });
  }

  updateResultCount(count) {
    if (this.countElement) {
      this.countElement.textContent = count;
    }
  }

  toggleNoResults(show) {
    if (this.noResults) {
      if (show) {
        this.noResults.classList.remove('hidden');
      } else {
        this.noResults.classList.add('hidden');
      }
    }
  }

  resetFilters() {
    this.filters = {
      search: '',
      producer: [],
      group: [],
      industry: []
    };

    if (this.searchInput) {
      this.searchInput.value = '';
    }

    this.filterCheckboxes.forEach(checkbox => {
      checkbox.checked = false;
    });

    // Reset dropdown searches
    this.dropdownSearches.forEach(searchInput => {
      searchInput.value = '';
    });

    this.updateCounts();
    this.closeAllDropdowns();
    this.filterItems();
  }
}

// Auto-inicjalizacja
const ready = (callback) => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
  } else {
    callback();
  }
};

ready(() => {
  console.log('🚀 Catalogues.js DOM Ready');
  new CataloguesFilter();
});