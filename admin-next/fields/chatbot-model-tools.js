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

  _getFormField(name) {
    if (typeof document === 'undefined') return null;
    return document.querySelector(`[name="data[${name}]"]`) ||
      document.getElementById(`data[${name}]`) ||
      document.getElementsByName(`data[${name}]`)[0] ||
      document.querySelector(`input[name*="${name}"], select[name*="${name}"]`);
  }

  async _fetchModels() {
    const fetchBtn = this.shadowRoot.getElementById('fetch-btn');
    const statusEl = this.shadowRoot.getElementById('status');
    const selectWrapper = this.shadowRoot.getElementById('select-wrapper');
    const selectEl = this.shadowRoot.getElementById('model-select');

    const provider = (this._getFormField('provider') || {}).value || 'omniroute';
    const apiKey = (this._getFormField('api_key') || {}).value || '';
    const customEndpoint = (this._getFormField('custom_endpoint') || {}).value || '';

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
            const modelInput = this._getFormField('model');
            if (modelInput) {
              modelInput.value = selectEl.value;
              modelInput.dispatchEvent(new Event('input', { bubbles: true }));
              modelInput.dispatchEvent(new Event('change', { bubbles: true }));
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

    const provider = (this._getFormField('provider') || {}).value || 'omniroute';
    const apiKey = (this._getFormField('api_key') || {}).value || '';
    const model = (this._getFormField('model') || {}).value || 'gemini-3.1-flash-lite';
    const customEndpoint = (this._getFormField('custom_endpoint') || {}).value || '';
    const fallbackEndpoint = (this._getFormField('fallback_endpoint') || {}).value || '';

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
          gap: 12px;
          flex-wrap: wrap;
          align-items: center;
        }

        button {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          font-weight: 600;
          font-size: 13px;
          padding: 8px 16px;
          border-radius: 6px;
          border: none;
          cursor: pointer;
          transition: all 0.2s ease;
        }

        button:disabled {
          opacity: 0.6;
          cursor: not-allowed;
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

    this.shadowRoot.getElementById('fetch-btn').addEventListener('click', () => this._fetchModels());
    this.shadowRoot.getElementById('test-btn').addEventListener('click', () => this._testModelHealth());
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, ChatbotModelTools);
}
