/* Carbon keeps ISO date-only values on each picker; the transfer UI listens for change. */
(() => {
    const inputStyles = new CSSStyleSheet();
    inputStyles.replaceSync(':host([kind="single"]) .cds--date-picker__input { inline-size: 100%; } .cds--date-picker-input__wrapper, .cds--date-picker-input__wrapper > span { inline-size: 100%; }');
    document.querySelectorAll('.csm-media-dates cds-date-picker').forEach(picker => {
        picker.addEventListener('cds-date-picker-changed', () => {
            picker.dispatchEvent(new Event('change', {bubbles:true}));
        });
        // Carbon's single-date input has a fixed 18rem width; allow narrow panels to fit.
        const input = picker.querySelector('cds-date-picker-input');
        input.updateComplete.then(() => {
            input.shadowRoot.adoptedStyleSheets = [...input.shadowRoot.adoptedStyleSheets, inputStyles];
        });
    });
})();
