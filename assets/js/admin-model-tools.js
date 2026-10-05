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
      const exactSelects = deepQueryOuter('select[name="data[provider]"], [data-field="provider"] select, select[name="provider"], #provider, #data\\[provider\\]');
      for (const sel of exactSelects) {
        if (sel.value) return sel.value.trim().toLowerCase();
        if (sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) {
          const optVal = (sel.options[sel.selectedIndex].value || '').trim().toLowerCase();
          if (optVal) return optVal;
          const optTxt = (sel.options[sel.selectedIndex].textContent || '').trim().toLowerCase();
          if (optTxt.includes('groq')) return 'groq';
          if (optTxt.includes('openrouter')) return 'openrouter';
          if (optTxt.includes('openai')) return 'openai';
          if (optTxt.includes('gemini')) return 'gemini';
          if (optTxt.includes('ollama')) return 'ollama';
          if (optTxt.includes('custom') || optTxt.includes('omniroute')) return 'omniroute';
        }
      }

      const labels = deepQueryOuter('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase();
        if (txt.includes('ai provider engine') || txt.includes('select your preferred ai provider')) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const sel = deepQueryOuter('select', parent)[0];
            if (sel) {
              if (sel.value) return sel.value.trim().toLowerCase();
              if (sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) {
                const optVal = (sel.options[sel.selectedIndex].value || '').trim().toLowerCase();
                if (optVal) return optVal;
                const optTxt = (sel.options[sel.selectedIndex].textContent || '').trim().toLowerCase();
                if (optTxt.includes('groq')) return 'groq';
                if (optTxt.includes('openrouter')) return 'openrouter';
                if (optTxt.includes('openai')) return 'openai';
                if (optTxt.includes('gemini')) return 'gemini';
                if (optTxt.includes('ollama')) return 'ollama';
                if (optTxt.includes('custom') || optTxt.includes('omniroute')) return 'omniroute';
              }
            }
          }
        }
      }

      const allSelects = deepQueryOuter('select');
      for (const sel of allSelects) {
        if (sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) {
          const optTxt = (sel.options[sel.selectedIndex].textContent || '').trim().toLowerCase();
          if (optTxt.includes('groq cloud') || optTxt.includes('groq')) return 'groq';
          if (optTxt.includes('openrouter ai') || optTxt.includes('openrouter')) return 'openrouter';
          if (optTxt.includes('openai official')) return 'openai';
          if (optTxt.includes('google gemini') || optTxt.includes('gemini')) return 'gemini';
          if (optTxt.includes('ollama local') || optTxt.includes('ollama')) return 'ollama';
          if (optTxt.includes('custom openai') || optTxt.includes('omniroute')) return 'omniroute';
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
