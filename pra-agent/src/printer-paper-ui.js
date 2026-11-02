/* PC-local paper modes; receipt/KOT routing is deliberately a separate setting. */
(function (root) {
  function render(container, printers, profiles) {
    container.replaceChildren();
    const names = new Set([...printers.map(p => p.name), ...Object.keys(profiles)]);
    if (!names.size) container.textContent = 'Printer list load karne ke liye Refresh dabayein.';
    for (const name of names) {
      const label = document.createElement('label');
      label.textContent = name + (printers.some(p => p.name === name) ? '' : ' (abhi offline / unavailable)');
      const select = document.createElement('select');
      select.style.cssText = 'display:block;width:100%;padding:8px;margin:5px 0 12px;color:#111;';
      for (const [value, text] of [['', 'Windows paper setting (pehle jaisa)'], ['58', '58mm roll — content ke mutabiq lambai'], ['80', '80mm roll — content ke mutabiq lambai']]) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
      }
      select.value = Object.hasOwn(profiles, name) ? String(profiles[name]) : '';
      select.addEventListener('change', () => {
        if (select.value) Object.defineProperty(profiles, name, {value: Number(select.value), writable: true, enumerable: true, configurable: true});
        else delete profiles[name];
      });
      label.appendChild(select);
      container.appendChild(label);
    }
  }
  root.TaxNestPrinterPaper = { render };
})(window);
