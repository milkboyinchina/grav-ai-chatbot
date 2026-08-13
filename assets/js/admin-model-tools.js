(function () {
  function getFormFieldVal(name) {
    if (typeof document === 'undefined') return '';

    if (name === 'provider') {
      const selects = Array.from(document.querySelectorAll('select'));
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

    const directSelectors = [
      `input[name="data[${name}]"]`,
      `select[name="data[${name}]"]`,
      `input[name="${name}"]`,
      `select[name="${name}"]`,
      `#${name}`,
      `[data-field="${name}"] input`,
      `[data-field="${name}"] select`
    ];
    for (const sel of directSelectors) {
      const el = document.querySelector(sel);
      if (el && el.value !== undefined && el.value !== null && el.value.trim() !== '') {
        return el.value.trim();
      }
    }

    const allInputs = Array.from(document.querySelectorAll('input, select, textarea'));
    for (const input of allInputs) {
      const n = (input.name || '').toLowerCase();
      const id = (input.id || '').toLowerCase();
      const ph = (input.placeholder || '').toLowerCase();
      const df = (input.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();

      let isMatch = false;
      if (name === 'api_key' && (n.includes('key') || id.includes('key') || df.includes('key') || ph.includes('key') || ph.includes('token'))) {
        isMatch = true;
      } else if (name === 'custom_endpoint' && (n.includes('custom') || id.includes('custom') || df.includes('custom') || ph.includes('custom') || ph.includes('110.120'))) {
        isMatch = true;
      } else if (name === 'fallback_endpoint' && (n.includes('fallback') || id.includes('fallback') || df.includes('fallback'))) {
        isMatch = true;
      } else if (name === 'model' && (n.includes('model') || id.includes('model') || df.includes('model') || ph.includes('gemini') || ph.includes('gpt'))) {
        isMatch = true;
      }

      if (isMatch && input.value !== undefined && input.value !== null && input.value.trim() !== '') {
        return input.value.trim();
      }
    }

    return '';
  }

  function setFormFieldVal(name, val) {
    if (typeof document === 'undefined') return;

    let targetInputs = Array.from(document.querySelectorAll(
      `input[name="data[${name}]"], select[name="data[${name}]"], input[name="${name}"], select[name="${name}"], #${name}, [data-field="${name}"] input, [data-field="${name}"] select`
    ));

    if (targetInputs.length === 0) {
      const allInputs = Array.from(document.querySelectorAll('input, select, textarea'));
      for (const input of allInputs) {
        const n = (input.name || '').toLowerCase();
        const id = (input.id || '').toLowerCase();
        const ph = (input.placeholder || '').toLowerCase();
        const df = (input.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();

        const isMatch = (name === 'model' && (n.includes('model') || id.includes('model') || df.includes('model') || ph.includes('gemini') || ph.includes('gpt')));
        if (isMatch) {
          targetInputs.push(input);
        }
      }
    }

    if (targetInputs.length === 0) {
      const labels = Array.from(document.querySelectorAll('label, span, div, .form-label'));
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase();
        if (txt.includes('model identifier') || txt.includes('model')) {
          const parent = lbl.closest('.form-field, .field, .form-group, div');
          if (parent) {
            const inp = parent.querySelector('input, select');
            if (inp) targetInputs.push(inp);
          }
        }
      }
    }

    for (const el of targetInputs) {
      const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
      if (nativeSetter) {
        nativeSetter.call(el, val);
      } else {
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
