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
    if (name === 'model') {
      const candidates = deepQueryOuter('input[name="data[model]"], [data-field="model"] input, #model, #data\\[model\\]');
      for (const el of candidates) {
        const fieldAttr = (el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
        const nameAttr = (el.name || '').toLowerCase();
        if ((fieldAttr === 'model' || nameAttr === 'data[model]' || nameAttr === 'model') &&
            !fieldAttr.includes('operations') && !fieldAttr.includes('context') && !fieldAttr.includes('timeout') &&
            !fieldAttr.includes('tokens') && !fieldAttr.includes('key') && !fieldAttr.includes('endpoint')) {
          return [el];
        }
      }

      const labels = deepQueryOuter('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase().trim();
        if (txt === 'model identifier' || txt === 'model identifier *' || (txt.includes('model identifier') && !txt.includes('context') && !txt.includes('tools'))) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const inps = deepQueryOuter('input', parent);
            if (inps.length > 0) return [inps[0]];
          }
        }
      }

      return [];
    }

    if (name === 'api_key') {
      const exactSelectors = [
        'input[name="data[api_key]"]',
        '[data-field="api_key"] input',
        '#data\\[api_key\\]',
        '#api_key'
      ];
      for (const sel of exactSelectors) {
        const found = deepQueryOuter(sel);
        if (found.length > 0) return [found[0]];
      }

      const labels = deepQueryOuter('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase().trim();
        if (txt.includes('api key') || txt.includes('secret token')) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const inps = deepQueryOuter('input', parent);
            if (inps.length > 0) return [inps[0]];
          }
        }
      }

      return [];
    }

    if (name === 'custom_endpoint' || name === 'fallback_endpoint') {
      const exactSelectors = [
        `input[name="data[${name}]"]`,
        `[data-field="${name}"] input`,
        `#data\\[${name}\\]`,
        `#${name}`
      ];
      for (const sel of exactSelectors) {
        const found = deepQueryOuter(sel);
        if (found.length > 0) return [found[0]];
      }
      return [];
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
