(function () {
  function deepQueryAll(selector, root = document) {
    let results = Array.from(root.querySelectorAll(selector));
    const allHosts = root.querySelectorAll('*');
    for (const host of allHosts) {
      if (host.shadowRoot) {
        results = results.concat(deepQueryAll(selector, host.shadowRoot));
      }
    }
    return results;
  }

  function findTargetInputs(name) {
    const results = [];

    const directInputs = deepQueryAll(
      `input[name="data[${name}]"], select[name="data[${name}]"], input[name="${name}"], select[name="${name}"], #${name}, [data-field="${name}"] input, [data-field="${name}"] select, input[name*="${name}"], select[name*="${name}"], [data-field*="${name}"] input`
    );
    results.push(...directInputs);

    if (results.length === 0) {
      const labels = deepQueryAll('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase();
        if (txt.includes('model identifier') || (name === 'model' && txt.includes('model'))) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const inps = deepQueryAll('input, select', parent);
            results.push(...inps);
          }
        }
      }
    }

    if (results.length === 0) {
      const phInputs = deepQueryAll('input[placeholder*="gemini"], input[placeholder*="gpt"], input[placeholder*="llama"]');
      results.push(...phInputs);
    }

    return Array.from(new Set(results));
  }

  function getFormFieldVal(name) {
    if (typeof document === 'undefined') return '';

    if (name === 'provider') {
      const selects = deepQueryAll('select');
      for (const sel of selects) {
        const n = (sel.name || '').toLowerCase();
        const id = (sel.id || '').toLowerCase();
        const df = (sel.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
        if (n.includes('provider') || id.includes('provider') || df.includes('provider')) {
          if (sel.value) return sel.value.trim();
        }
      }
      return 'gemini';
    }

    const inputs = findTargetInputs(name);
    for (const el of inputs) {
      if (el && el.value !== undefined && el.value !== null && el.value.trim() !== '') {
        return el.value.trim();
      }
    }

    return '';
  }

  function setFormFieldVal(name, val) {
    if (typeof document === 'undefined') return;

    const targetInputs = findTargetInputs(name);
    for (const el of targetInputs) {
      try {
        const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set ||
                             Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value')?.set;
        if (nativeSetter) {
          nativeSetter.call(el, val);
        } else {
          el.value = val;
        }
      } catch (e) {
        el.value = val;
      }

      el.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
      el.dispatchEvent(new Event('change', { bubbles: true, composed: true }));
      el.dispatchEvent(new Event('blur', { bubbles: true, composed: true }));
    }
  }

  window.GravChatbotModelTools = {
    getVal: getFormFieldVal,
    setVal: setFormFieldVal
  };
})();
