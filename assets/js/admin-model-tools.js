(function () {
  function deepQueryOuter(selector, root = document) {
    let elements = Array.from(root.querySelectorAll(selector)).filter(el => {
      const container = document.querySelector('chatbot-model-tools');
      return !container || (!container.contains(el) && el.getRootNode() !== container.shadowRoot);
    });
    const allHosts = root.querySelectorAll('*');
    for (const host of allHosts) {
      const container = document.querySelector('chatbot-model-tools');
      if (host !== container && host.shadowRoot) {
        elements = elements.concat(deepQueryOuter(selector, host.shadowRoot));
      }
    }
    return elements;
  }

  function findTargetInputs(name) {
    const exactSelectors = [
      `input[name="data[${name}]"]`,
      `select[name="data[${name}]"]`,
      `[data-field="${name}"] input`,
      `[data-field="${name}"] select`,
      `input[name="${name}"]`,
      `select[name="${name}"]`,
      `#${name}`,
      `#data\\[${name}\\]`
    ];

    for (const sel of exactSelectors) {
      const found = deepQueryOuter(sel);
      if (found.length > 0) {
        return found;
      }
    }

    const attrMatches = deepQueryOuter('input, select').filter(el => {
      const n = (el.name || '').toLowerCase();
      const id = (el.id || '').toLowerCase();
      const df = (el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
      const ph = (el.placeholder || '').toLowerCase();

      if (name === 'api_key') {
        return (n.includes('api_key') || id.includes('api_key') || df.includes('api_key') || ph.includes('api key') || ph.includes('secret token'));
      }
      if (name === 'custom_endpoint') {
        return (n.includes('custom_endpoint') || id.includes('custom_endpoint') || df.includes('custom_endpoint') || ph.includes('110.120'));
      }
      if (name === 'fallback_endpoint') {
        return (n.includes('fallback_endpoint') || id.includes('fallback_endpoint') || df.includes('fallback_endpoint'));
      }
      if (name === 'model') {
        return (n.includes('model') || id.includes('model') || df.includes('model') || ph.includes('gemini-3.1'));
      }
      return false;
    });

    if (attrMatches.length > 0) {
      return attrMatches;
    }

    const labels = deepQueryOuter('label, span, div, .form-label');
    for (const lbl of labels) {
      const txt = (lbl.textContent || '').toLowerCase();
      const isMatch = (name === 'api_key' && (txt.includes('api key') || txt.includes('secret token'))) ||
                      (name === 'model' && (txt.includes('model identifier') || txt.includes('model'))) ||
                      (name === 'custom_endpoint' && (txt.includes('custom url') || txt.includes('custom endpoint'))) ||
                      (name === 'fallback_endpoint' && txt.includes('fallback endpoint'));

      if (isMatch) {
        const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
        if (parent) {
          const inps = deepQueryOuter('input, select', parent);
          if (inps.length > 0) return inps;
        }
      }
    }

    return [];
  }

  function getFormFieldVal(name) {
    if (typeof document === 'undefined') return '';

    if (name === 'provider') {
      const selects = deepQueryOuter('select');
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
