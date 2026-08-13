const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-model-tools';

class ChatbotModelTools extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._status = null;
    this._models = [];
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

  _getFormFieldVal(name) {
    if (typeof document === 'undefined') return '';
    const selectors = [
      `[name="data[${name}]"]`,
      `[name="${name}"]`,
      `#${name}`,
      `[data-field="${name}"] input`,
      `[data-field="${name}"] select`,
      `input[name*="${name}"]`,
      `select[name*="${name}"]`,
      `textarea[name*="${name}"]`
    ];

    for (const sel of selectors) {
      const el = document.querySelector(sel);
      if (el && el.value !== undefined && el.value !== null) {
        return el.value.trim();
      }
    }

    // Advanced search: find input by sibling label text
    const labels = Array.from(document.querySelectorAll('label, .form-label'));
    for (const lbl of labels) {
      const txt = (lbl.textContent || '').toLowerCase();
      if (txt.includes(name.replace('_', ' ')) ||
        (name === 'api_key' && (txt.includes('api key') || txt.includes('key'))) ||
        (name === 'custom_endpoint' && (txt.includes('custom url') || txt.includes('openai compatible url')))) {
        const parent = lbl.closest('.form-field, .field, div');
        if (parent) {
          const input = parent.querySelector('input, select, textarea');
          if (input && input.value !== undefined) {
            return input.value.trim();
          }
        }
      }
    }

    return '';
  }

  async _testApiKey() {
    const keyBtn = this.shadowRoot.getElementById('key-btn');
    const statusEl = this.shadowRoot.getElementById('status');

    const provider = this._getFormFieldVal('provider') || 'omniroute';
    const apiKey = this._getFormFieldVal('api_key');
    const customEndpoint = this._getFormFieldVal('custom_endpoint');

    if (keyBtn) keyBtn.disabled = true;

    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.className = 'status info';
      statusEl.innerHTML = '⏳ Verifying API Key authentication with provider endpoint...';
    }

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
      if (keyBtn) keyBtn.disabled = false;

      if (statusEl) {
        statusEl.className = data.success ? 'status success' : 'status error';
        statusEl.innerHTML = data.message;
      }
    } catch (err) {
      if (keyBtn) keyBtn.disabled = false;
      if (statusEl) {
        statusEl.className = 'status error';
        statusEl.innerHTML = `❌ Error: ${err.message}`;
      }
    }
  }

  async _fetchModels() {
    const fetchBtn = this.shadowRoot.getElementById('fetch-btn');
    const statusEl = this.shadowRoot.getElementById('status');
    const selectWrapper = this.shadowRoot.getElementById('select-wrapper');
    const selectEl = this.shadowRoot.getElementById('model-select');

    const provider = this._getFormFieldVal('provider') || 'omniroute';
    const apiKey = this._getFormFieldVal('api_key');
    const customEndpoint = this._getFormFieldVal('custom_endpoint');

    if (fetchBtn) fetchBtn.disabled = true;

    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.className = 'status info';
      statusEl.innerHTML = '⏳ Querying active model list from provider API...';
    }

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
      if (fetchBtn) fetchBtn.disabled = false;

      if (data.success && data.models && data.models.length) {
        this._models = data.models;
        if (statusEl) {
          statusEl.className = 'status success';
          statusEl.innerHTML = `✅ Successfully retrieved ${data.models.length} active models!`;
        }

        if (selectWrapper && selectEl) {
          selectWrapper.style.display = 'block';
          selectEl.innerHTML = data.models.map(m => `<option value="${m}">${m}</option>`).join('');
          selectEl.onchange = () => {
            const selectors = [
              '[name="data[model]"]',
              '[name="model"]',
              '#model',
              'input[name*="model"]'
            ];
            for (const sel of selectors) {
              const el = document.querySelector(sel);
              if (el) {
                el.value = selectEl.value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
              }
            }
          };
        }
      } else {
        if (statusEl) {
          statusEl.className = 'status error';
          statusEl.innerHTML = `❌ ${data.message || 'Failed to retrieve models'}`;
        }
      }
    } catch (err) {
      if (fetchBtn) fetchBtn.disabled = false;
      if (statusEl) {
        statusEl.className = 'status error';
        statusEl.innerHTML = `❌ Error: ${err.message}`;
      }
    }
  }

  async _testModelHealth() {
    const testBtn = this.shadowRoot.getElementById('test-btn');
    const statusEl = this.shadowRoot.getElementById('status');

    const provider = this._getFormFieldVal('provider') || 'omniroute';
    const apiKey = this._getFormFieldVal('api_key');
    const model = this._getFormFieldVal('model') || 'gemini-3.1-flash-lite';
    const customEndpoint = this._getFormFieldVal('custom_endpoint');
    const fallbackEndpoint = this._getFormFieldVal('fallback_endpoint');

    if (testBtn) testBtn.disabled = true;

    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.className = 'status info';
      statusEl.innerHTML = `⏳ Sending live health check ping to model '${model}'...`;
    }

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
      if (testBtn) testBtn.disabled = false;

      if (statusEl) {
        statusEl.className = data.success ? 'status success' : 'status error';
        statusEl.innerHTML = data.message;
      }
    } catch (err) {
      if (testBtn) testBtn.disabled = false;
      if (statusEl) {
        statusEl.className = 'status error';
        statusEl.innerHTML = `❌ Connection Error: ${err.message}`;
      }
    }
  }

  _render() {
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

        .tools-container {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 8px;
          padding: 16px;
          box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

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

        .btn-key {
          background: #d97706;
          color: #ffffff;
        }

        .btn-key:hover:not(:disabled) {
          background: #b45309;
        }

        .btn-fetch {
          background: #4f46e5;
          color: #ffffff;
        }

        .btn-fetch:hover:not(:disabled) {
          background: #4338ca;
        }

        .btn-test {
          background: #059669;
          color: #ffffff;
        }

        .btn-test:hover:not(:disabled) {
          background: #047857;
        }

        .status {
          margin-top: 12px;
          padding: 12px 14px;
          border-radius: 6px;
          font-size: 13px;
          font-weight: 600;
          display: none;
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

        .select-wrapper {
          margin-top: 14px;
          display: none;
        }

        .select-wrapper label {
          display: block;
          font-weight: 600;
          font-size: 13px;
          color: #334155;
          margin-bottom: 6px;
        }

        select {
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

        select:focus {
          outline: none;
          border-color: #4f46e5;
          box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
      </style>

      <div class="tools-container">
        <div class="btn-group">
          <button type="button" id="key-btn" class="btn-key">
            🔑 Test API Key
          </button>
          <button type="button" id="fetch-btn" class="btn-fetch">
            🔄 Retrieve Active Models from API
          </button>
          <button type="button" id="test-btn" class="btn-test">
            ⚡ Test Model Health
          </button>
        </div>

        <div id="status" class="status"></div>

        <div id="select-wrapper" class="select-wrapper">
          <label>Available Active Models (Click to select & populate Model field):</label>
          <select id="model-select"></select>
        </div>
      </div>
    `;

    this.shadowRoot.getElementById('key-btn').addEventListener('click', () => this._testApiKey());
    this.shadowRoot.getElementById('fetch-btn').addEventListener('click', () => this._fetchModels());
    this.shadowRoot.getElementById('test-btn').addEventListener('click', () => this._testModelHealth());
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, ChatbotModelTools);
}
