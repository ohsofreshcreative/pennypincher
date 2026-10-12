document.addEventListener('alpine:init', () => {
  window.Alpine.data('msCurrencyCalc', (locations = []) => ({
    locations,
    mode: 'buy',
    locationSlug: locations[0]?.slug ?? '',
    currencyCode: locations[0]?.rates?.[0]?.code ?? '',
    amount: 100,

    get currentLocation() {
      return this.locations.find((l) => l.slug === this.locationSlug) || null;
    },
    get currentRates() {
      return this.currentLocation?.rates ?? [];
    },
    get currentRow() {
      return this.currentRates.find((r) => r.code === this.currencyCode) || null;
    },
    get rate() {
      if (!this.currentRow) return 0;
      return this.mode === 'buy' ? this.currentRow.sell : this.currentRow.buy;
    },
    get formattedResult() {
      const v = (Number(this.amount) || 0) * this.rate;
      return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    init() {
      this.$watch('locationSlug', () => {
        const codes = this.currentRates.map((r) => r.code);
        if (!codes.includes(this.currencyCode)) {
          this.currencyCode = codes[0] ?? '';
        }
      });
    },
  }));
});