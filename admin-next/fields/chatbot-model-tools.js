const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-model-tools';

class ChatbotModelTools extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._currentStep = 1;
    this._models = [];
    this._selectedModel = '';
    this._status = { type: '', message: '' };
    this._isManualMode = false;
    this._testedHealthSuccess = false;
    this._testedModel = '';
  }

  connectedCallback() {
    this._render();
  }

  set field(f) {
    this._field = f;
    this._render();
  }

  set value(v) {
    this._value = v;
    this._render();
  }

  get value() {
    return this._value;
  }

  _deepQueryOuter(selector, root = document) {
    let elements = Array.from(root.querySelectorAll(selector)).filter(el => !this.contains(el) && el.getRootNode() !== this.shadowRoot);
    const allHosts = root.querySelectorAll('*');
    for (const host of allHosts) {
      if (host !== this && host.shadowRoot) {
        elements = elements.concat(this._deepQueryOuter(selector, host.shadowRoot));
      }
    }
    return elements;
  }

  _findTargetInputs(name) {
    if (name === 'model') {
      const candidates = this._deepQueryOuter('input[name="data[model]"], [data-field="model"] input, #model, #data\\[model\\]');
      for (const el of candidates) {
        const fieldAttr = (el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
        const nameAttr = (el.name || '').toLowerCase();
        if ((fieldAttr === 'model' || nameAttr === 'data[model]' || nameAttr === 'model') &&
            !fieldAttr.includes('operations') && !fieldAttr.includes('context') && !fieldAttr.includes('timeout') &&
            !fieldAttr.includes('tokens') && !fieldAttr.includes('key') && !fieldAttr.includes('endpoint')) {
          return [el];
        }
      }

      const labels = this._deepQueryOuter('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase().trim();
        if (txt === 'model identifier' || txt === 'model identifier *' || (txt.includes('model identifier') && !txt.includes('context') && !txt.includes('tools'))) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const inps = this._deepQueryOuter('input', parent);
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
        const found = this._deepQueryOuter(sel);
        if (found.length > 0) return [found[0]];
      }

      const labels = this._deepQueryOuter('label, span, div, .form-label');
      for (const lbl of labels) {
        const txt = (lbl.textContent || '').toLowerCase().trim();
        if (txt.includes('api key') || txt.includes('secret token')) {
          const parent = lbl.closest('.form-field, .field, .form-group, div, fieldset') || lbl.parentElement;
          if (parent) {
            const inps = this._deepQueryOuter('input', parent);
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
        const found = this._deepQueryOuter(sel);
        if (found.length > 0) return [found[0]];
      }
      return [];
    }

    return [];
  }

  _getFormFieldVal(name) {
    if (typeof document === 'undefined') return '';

    if (name === 'provider') {
      const selects = this._deepQueryOuter('select');
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

    const inputs = this._findTargetInputs(name);
    for (const el of inputs) {
      if (el && el.value !== undefined && el.value !== null && el.value.trim() !== '') {
        return el.value.trim();
      }
    }

    return '';
  }

  _setFormFieldVal(name, val) {
    if (typeof document === 'undefined') return;

    const targetInputs = this._findTargetInputs(name);
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

  async _testApiKey() {
    const provider = this._getFormFieldVal('provider') || 'gemini';
    const apiKey = this._getFormFieldVal('api_key');
    const customEndpoint = this._getFormFieldVal('custom_endpoint');

    if (!apiKey && ['gemini', 'groq', 'openai', 'openrouter'].includes(provider)) {
      this._status = { type: 'error', message: '❌ API Key is missing. Please enter your API Key into the API Key field above before testing.' };
      this._render();
      return;
    }

    this._status = { type: 'info', message: '⏳ Verifying API Key authentication...' };
    this._render();

    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }

      const res = await fetch('/chatbot-api?t=' + Date.now(), {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          action: 'test_api_key',
          provider: provider,
          api_key: apiKey,
          custom_endpoint: customEndpoint
        })
      });

      const data = await res.json();
      if (data.success) {
        this._status = { type: 'success', message: data.message };
        this._currentStep = 2; // Advance to Step 2 on success
      } else {
        this._status = { type: 'error', message: data.message };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Error: ${err.message}` };
    }
    this._render();
  }

  async _fetchModels() {
    const provider = this._getFormFieldVal('provider') || 'gemini';
    const apiKey = this._getFormFieldVal('api_key');
    const customEndpoint = this._getFormFieldVal('custom_endpoint');

    this._status = { type: 'info', message: '⏳ Querying active model list from provider API...' };
    this._render();

    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }

      const res = await fetch('/chatbot-api?t=' + Date.now(), {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          action: 'fetch_models',
          provider: provider,
          api_key: apiKey,
          custom_endpoint: customEndpoint
        })
      });

      const data = await res.json();
      if (data.success && data.models && data.models.length) {
        this._models = data.models;
        this._isManualMode = false;
        if (!this._selectedModel) {
          this._selectedModel = data.models[0];
        }
        this._status = { type: 'success', message: `✅ Successfully retrieved ${data.models.length} active models!` };
        this._currentStep = 3; // Advance to Step 3 on success
      } else {
        this._status = { type: 'error', message: `❌ ${data.message || 'Failed to retrieve models'}` };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Error: ${err.message}` };
    }
    this._render();
  }

  async _testModelHealth(selectedModel) {
    const provider = this._getFormFieldVal('provider') || 'gemini';
    const apiKey = this._getFormFieldVal('api_key');
    const model = selectedModel || this._selectedModel || this._getFormFieldVal('model') || 'gemini-3.1-flash-lite';
    const customEndpoint = this._getFormFieldVal('custom_endpoint');
    const fallbackEndpoint = this._getFormFieldVal('fallback_endpoint');

    this._testedModel = model;
    this._selectedModel = model;
    this._testedHealthSuccess = false;
    this._status = { type: 'info', message: `⏳ Sending live health check ping to model '${model}'...` };
    this._render();

    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }

      const res = await fetch('/chatbot-api?t=' + Date.now(), {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          action: 'test_model_health',
          provider: provider,
          api_key: apiKey,
          model: model,
          custom_endpoint: customEndpoint,
          fallback_endpoint: fallbackEndpoint
        })
      });

      const data = await res.json();
      if (data.success) {
        this._testedHealthSuccess = true;
        this._status = { type: 'success', message: data.message };
      } else {
        this._status = { type: 'error', message: data.message };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Connection Error: ${err.message}` };
    }
    this._render();
  }

  _useThisModel() {
    const targetModel = this._testedModel || this._selectedModel;
    if (!targetModel) return;
    this._setFormFieldVal('model', targetModel);
    this._status = { type: 'success', message: `🎉 Model '${targetModel}' successfully applied to Model Identifier field!` };
    this._render();
  }

  _render() {
    const step = this._currentStep;
    const status = this._status;
    const activeSelectedModel = this._testedModel || this._selectedModel || this._getFormFieldVal('model');

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          width: 100%;
          max-width: 100%;
          box-sizing: border-box;
          margin-top: 8px;
          margin-bottom: 16px;
          font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .wizard-container {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 10px;
          padding: 18px;
          box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        /* Step Progress Bar */
        .step-progress {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 16px;
          padding-bottom: 12px;
          border-bottom: 1px solid #f1f5f9;
        }

        .step-item {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 12px;
          font-weight: 600;
          color: #94a3b8;
          cursor: pointer;
        }

        .step-item.active {
          color: #4f46e5;
        }

        .step-item.completed {
          color: #059669;
        }

        .step-num {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 22px;
          height: 22px;
          border-radius: 50%;
          background: #e2e8f0;
          color: #475569;
          font-size: 11px;
        }

        .step-item.active .step-num {
          background: #4f46e5;
          color: #ffffff;
        }

        .step-item.completed .step-num {
          background: #059669;
          color: #ffffff;
        }

        .step-divider {
          flex: 1;
          height: 2px;
          background: #e2e8f0;
          margin: 0 10px;
        }

        /* Step Content */
        .step-content {
          margin-bottom: 14px;
        }

        .step-title {
          font-size: 14px;
          font-weight: 700;
          color: #1e293b;
          margin-bottom: 4px;
        }

        .step-desc {
          font-size: 12px;
          color: #64748b;
          margin-bottom: 14px;
        }

        .note-hint {
          font-size: 11px;
          color: #6366f1;
          background: #eef2ff;
          padding: 6px 10px;
          border-radius: 4px;
          margin-bottom: 12px;
          display: inline-block;
          font-weight: 500;
        }

        /* Buttons & Actions */
        .btn-group {
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
          align-items: center;
        }

        button {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          font-weight: 600;
          font-size: 13px;
          padding: 8px 14px;
          border-radius: 6px;
          border: none;
          cursor: pointer;
          transition: all 0.2s ease;
        }

        button:disabled {
          opacity: 0.6;
          cursor: not-allowed;
        }

        .btn-primary {
          background: #4f46e5;
          color: #ffffff;
        }

        .btn-primary:hover:not(:disabled) {
          background: #4338ca;
        }

        .btn-success {
          background: #059669;
          color: #ffffff;
        }

        .btn-success:hover:not(:disabled) {
          background: #047857;
        }

        .btn-warning {
          background: #d97706;
          color: #ffffff;
        }

        .btn-warning:hover:not(:disabled) {
          background: #b45309;
        }

        .btn-secondary {
          background: #f1f5f9;
          color: #334155;
          border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover:not(:disabled) {
          background: #e2e8f0;
        }

        .btn-use-model {
          background: linear-gradient(135deg, #059669, #10b981);
          color: #ffffff;
          box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
        }

        .btn-use-model:hover:not(:disabled) {
          background: linear-gradient(135deg, #047857, #059669);
        }

        .status {
          margin-top: 14px;
          padding: 12px 14px;
          border-radius: 6px;
          font-size: 13px;
          font-weight: 600;
          word-break: break-word;
        }

        .status.info {
          background: #f1f5f9;
          color: #334155;
          border: 1px solid #cbd5e1;
        }

        .status.success {
          background: #d1fae5;
          color: #065f46;
          border: 1px solid #a7f3d0;
        }

        .status.error {
          background: #fee2e2;
          color: #991b1b;
          border: 1px solid #fca5a5;
        }

        .model-select-box {
          margin-top: 12px;
          margin-bottom: 14px;
        }

        .model-select-box label {
          display: block;
          font-weight: 600;
          font-size: 12px;
          color: #334155;
          margin-bottom: 6px;
        }

        select, input[type="text"] {
          width: 100%;
          padding: 10px;
          border-radius: 6px;
          border: 1px solid #cbd5e1;
          font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
          background: #f8fafc;
          font-size: 13px;
          color: #0f172a;
          box-sizing: border-box;
        }

        select:focus, input[type="text"]:focus {
          outline: none;
          border-color: #4f46e5;
          box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
      </style>

      <div class="wizard-container">
        <!-- Progress Bar -->
        <div class="step-progress">
          <div class="step-item ${step === 1 ? 'active' : (step > 1 ? 'completed' : '')}" id="step-nav-1">
            <span class="step-num">1</span> 🔑 Test Key
          </div>
          <div class="step-divider"></div>
          <div class="step-item ${step === 2 ? 'active' : (step > 2 ? 'completed' : '')}" id="step-nav-2">
            <span class="step-num">2</span> 🔄 Models
          </div>
          <div class="step-divider"></div>
          <div class="step-item ${step === 3 ? 'active' : ''}" id="step-nav-3">
            <span class="step-num">3</span> ⚡ Health Ping
          </div>
        </div>

        <!-- Status Message Banner -->
        ${status.message ? `<div class="status ${status.type}">${status.message}</div>` : ''}

        <!-- STEP 1: TEST API KEY -->
        ${step === 1 ? `
          <div class="step-content">
            <div class="step-title">Step 1: Test API Key Authentication</div>
            <div class="step-desc">Verify your authentication credentials directly with the provider endpoint.</div>
            <div class="note-hint">ℹ️ Note: This tests your newly typed API Key in the API Key field above before saving plugin settings.</div>
            <div class="btn-group">
              <button type="button" id="btn-test-key" class="btn-warning">🔑 Test API Key</button>
              <button type="button" id="btn-skip-1" class="btn-secondary">➡️ Skip to Step 2</button>
            </div>
          </div>
        ` : ''}

        <!-- STEP 2: RETRIEVE ACTIVE MODELS -->
        ${step === 2 ? `
          <div class="step-content">
            <div class="step-title">Step 2: Retrieve Active Models from API</div>
            <div class="step-desc">Fetch active available model IDs from your AI provider endpoint.</div>
            <div class="btn-group">
              <button type="button" id="btn-fetch-models" class="btn-primary">🔄 Retrieve Active Models from API</button>
              <button type="button" id="btn-back-2" class="btn-secondary">⬅️ Back to Step 1</button>
              <button type="button" id="btn-skip-2" class="btn-secondary">➡️ Skip to Step 3</button>
            </div>
            ${status.type === 'error' ? `
              <div style="margin-top:12px;" class="btn-group">
                <button type="button" id="btn-manual-2" class="btn-warning">✍️ Enter Model Manually</button>
              </div>
            ` : ''}
          </div>
        ` : ''}

        <!-- STEP 3: TEST MODEL HEALTH & APPLY MODEL -->
        ${step === 3 ? `
          <div class="step-content">
            <div class="step-title">Step 3: Test Model Health & Apply Model</div>
            <div class="step-desc">Select a model from the list or type an unlisted model ID to run a live health check ping.</div>
            
            <div class="model-select-box">
              ${!this._isManualMode && this._models.length > 0 ? `
                <label>Active Provider Models (${this._models.length} retrieved):</label>
                <select id="wizard-select-model">
                  ${this._models.map(m => `<option value="${m}" ${m === activeSelectedModel ? 'selected' : ''}>${m}</option>`).join('')}
                </select>
                <div style="margin-top:6px;">
                  <button type="button" id="btn-toggle-manual" class="btn-secondary" style="font-size:11px; padding:4px 8px;">✍️ Switch to Unlisted / Custom Model Input</button>
                </div>
              ` : `
                <label>Enter Custom / Unlisted Model ID:</label>
                <input type="text" id="wizard-input-model" placeholder="e.g. gemini-3.1-flash-lite, gpt-4o-mini, llama3.3" value="${activeSelectedModel || 'gemini-3.1-flash-lite'}" />
                ${this._models.length > 0 ? `
                  <div style="margin-top:6px;">
                    <button type="button" id="btn-toggle-select" class="btn-secondary" style="font-size:11px; padding:4px 8px;">📋 Choose from Retrieved Models List (${this._models.length})</button>
                  </div>
                ` : ''}
              `}
            </div>

            <div class="btn-group">
              <button type="button" id="btn-test-health" class="btn-success">⚡ Test Model Health</button>
              ${this._testedHealthSuccess ? `
                <button type="button" id="btn-use-model" class="btn-use-model">✨ Use This Model</button>
              ` : ''}
              <button type="button" id="btn-back-3" class="btn-secondary">⬅️ Back to Step 2</button>
            </div>
          </div>
        ` : ''}
      </div>
    `;

    const root = this.shadowRoot;

    // Step Nav Clicks
    root.getElementById('step-nav-1')?.addEventListener('click', () => { this._currentStep = 1; this._render(); });
    root.getElementById('step-nav-2')?.addEventListener('click', () => { this._currentStep = 2; this._render(); });
    root.getElementById('step-nav-3')?.addEventListener('click', () => { this._currentStep = 3; this._render(); });

    // Step 1 Controls
    root.getElementById('btn-test-key')?.addEventListener('click', () => this._testApiKey());
    root.getElementById('btn-skip-1')?.addEventListener('click', () => { this._currentStep = 2; this._render(); });

    // Step 2 Controls
    root.getElementById('btn-fetch-models')?.addEventListener('click', () => this._fetchModels());
    root.getElementById('btn-back-2')?.addEventListener('click', () => { this._currentStep = 1; this._render(); });
    root.getElementById('btn-skip-2')?.addEventListener('click', () => { this._currentStep = 3; this._render(); });
    root.getElementById('btn-manual-2')?.addEventListener('click', () => { this._isManualMode = true; this._currentStep = 3; this._render(); });

    // Step 3 Controls
    const wizardSelectEl = root.getElementById('wizard-select-model');
    if (wizardSelectEl) {
      wizardSelectEl.addEventListener('change', () => {
        this._selectedModel = wizardSelectEl.value;
        this._testedModel = wizardSelectEl.value;
      });
    }

    const wizardInputEl = root.getElementById('wizard-input-model');
    if (wizardInputEl) {
      wizardInputEl.addEventListener('input', () => {
        this._selectedModel = wizardInputEl.value;
        this._testedModel = wizardInputEl.value;
      });
    }

    root.getElementById('btn-toggle-manual')?.addEventListener('click', () => { this._isManualMode = true; this._render(); });
    root.getElementById('btn-toggle-select')?.addEventListener('click', () => { this._isManualMode = false; this._render(); });
    
    root.getElementById('btn-test-health')?.addEventListener('click', () => {
      let targetModel = '';
      const selectEl = root.getElementById('wizard-select-model');
      const inputEl = root.getElementById('wizard-input-model');
      if (selectEl) targetModel = selectEl.value;
      else if (inputEl) targetModel = inputEl.value;
      this._testModelHealth(targetModel);
    });

    root.getElementById('btn-use-model')?.addEventListener('click', () => this._useThisModel());
    root.getElementById('btn-back-3')?.addEventListener('click', () => { this._currentStep = 2; this._render(); });
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, ChatbotModelTools);
}
