const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-model-tools';

const PROVIDERS = [
  { value: 'claude', label: '🟣 Anthropic Claude API' },
  { value: 'deepseek', label: '🐋 DeepSeek API' },
  { value: 'gemini', label: '♊ Google Gemini API' },
  { value: 'groq', label: '⚡ Groq Cloud (Llama-3.3-70b / Fast)' },
  { value: 'ollama', label: '🔧 Custom OpenAI-Compatible Endpoint' },
  { value: 'omniroute', label: '🔀 OmniRoute Gateway (OpenAI-Compatible)' },
  { value: 'openai', label: '🤖 OpenAI Official API (GPT-4o / GPT-4o-mini)' },
  { value: 'opencode-go', label: '🟢 OpenCode Go Gateway' },
  { value: 'opencode-zen', label: '🟢 OpenCode Zen Gateway' },
  { value: 'openrouter', label: '🌐 OpenRouter AI Gateway' },
  { value: 'reflection', label: '🔮 Reflection AI API' },
  { value: 'xiaomi', label: '📱 Xiaomi Mimo API' },
  { value: 'zhipu', label: '🧠 Zhipu AI (GLM) API' }
];

const ENDPOINT_REQUIRED = ['ollama', 'omniroute'];

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

class ChatbotModelTools extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._provider = 'gemini';
    this._apiKey = '';
    this._customEndpoint = '';
    this._model = '';
    this._models = [];
    this._isManualMode = false;
    this._status = { type: '', message: '' };
    this._testedHealthSuccess = false;
    this._testedModel = '';
    this._busy = false;
    this._touched = { provider: false, apiKey: false, endpoint: false, model: false };
    this._prefilled = false;
  }

  connectedCallback() {
    this._render();
    this._schedulePrefill();
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

  _schedulePrefill() {
    const tryPrefill = () => {
      if (this._prefilled) return;
      const outerProvider = this._readOuter('provider');
      const outerApiKey = this._readOuter('api_key');
      const outerEndpoint = this._readOuter('custom_endpoint');
      const outerModel = this._readOuter('model');
      let changed = false;
      if (!this._touched.provider && outerProvider) { this._provider = outerProvider; changed = true; }
      if (!this._touched.apiKey && outerApiKey) { this._apiKey = outerApiKey; changed = true; }
      if (!this._touched.endpoint && outerEndpoint) { this._customEndpoint = outerEndpoint; changed = true; }
      if (!this._touched.model && outerModel) { this._model = outerModel; changed = true; }
      if (changed || !this._prefilled) {
        this._prefilled = true;
        this._render();
      }
    };
    setTimeout(tryPrefill, 100);
    setTimeout(tryPrefill, 500);
    setTimeout(tryPrefill, 1500);
  }

  // ---- Outer form helpers (used only for pre-fill + apply-back) ----
  _deepQueryOuter(selector, root = document) {
    let elements = [];
    try { elements = Array.from(root.querySelectorAll(selector)); } catch (e) { elements = []; }
    elements = elements.filter(el => !this.contains(el) && el.getRootNode() !== this.shadowRoot);
    const allHosts = root.querySelectorAll ? root.querySelectorAll('*') : [];
    for (const host of allHosts) {
      if (host !== this && host.shadowRoot) {
        elements = elements.concat(this._deepQueryOuter(selector, host.shadowRoot));
      }
    }
    return elements;
  }

  _isFilterInput(el) {
    if (!el) return true;
    try {
      if ((el.type || '').toLowerCase() === 'search') return true;
      const ph = (el.placeholder || '').toLowerCase();
      if (ph.includes('filter') || ph.includes('search')) return true;
      const aria = (el.getAttribute && (el.getAttribute('aria-label') || '').toLowerCase()) || '';
      if (aria.includes('filter')) return true;
      const id = (el.id || '').toLowerCase();
      const nm = (el.name || '').toLowerCase();
      if (id.includes('filter') || nm.includes('filter')) return true;
      if (id.includes('search') || nm.includes('search')) return true;
      if (el.closest) {
        const wrap = el.closest('[class*="filter"], [data-testid*="filter"]');
        if (wrap) return true;
      }
    } catch (e) { /* ignore */ }
    return false;
  }

  _candidateIdentity(el) {
    try {
      const parts = [
        el.name || '',
        el.id || '',
        (el.getAttribute && (el.getAttribute('data-field') || '')) || '',
        (el.closest && el.closest('[data-field]')?.getAttribute('data-field')) || ''
      ].join(' ').toLowerCase();
      return parts;
    } catch (e) {
      return '';
    }
  }

  _findByGrid(name) {
    // Admin2 Svelte rows: div.grid > [label div, field div]. No name/id/data-field on inputs.
    const tests = {
      provider: (t) => t.startsWith('🤖 ai provider engine') || t.startsWith('ai provider engine'),
      api_key: (t) => t.startsWith('api key') || t.startsWith('kunci api'),
      model: (t) => t.startsWith('model identifier') || t.startsWith('identifikasi model'),
      custom_endpoint: (t) => t.startsWith('🌐 custom url') || t.startsWith('custom url')
    };
    const test = tests[name];
    if (!test) return null;
    const wantTag = name === 'provider' ? 'SELECT' : 'INPUT';
    let grids = [];
    try { grids = Array.from(document.querySelectorAll('div.grid')); } catch (e) { grids = []; }
    for (const g of grids) {
      if (this.contains(g)) continue;
      const kids = g.children;
      if (!kids || kids.length < 2) continue;
      let lab = '';
      try { lab = (kids[0].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase(); } catch (e) { continue; }
      if (!test(lab)) continue;
      const fieldBox = kids[1];
      let inputs = [];
      try { inputs = Array.from(fieldBox.querySelectorAll(wantTag === 'SELECT' ? 'select' : 'input')); } catch (e) { continue; }
      for (const el of inputs) {
        if (this._isFilterInput(el)) continue;
        return el;
      }
    }
    return null;
  }

  _findOuterCandidates(name) {
    const out = [];
    // 0) Admin2 grid-based lookup (ground truth, immune to filter bar)
    const gridHit = this._findByGrid(name);
    if (gridHit) return [gridHit];
    const pushIfValid = (el, requireIdentity) => {
      if (!el) return;
      if (this.contains(el)) return;
      if (el.getRootNode && el.getRootNode() === this.shadowRoot) return;
      if (this._isFilterInput(el)) return;
      if (requireIdentity) {
        const ident = this._candidateIdentity(el);
        const keys = name === 'api_key' ? ['api_key', 'api-key', 'apikey', 'secret'] :
          name === 'custom_endpoint' ? ['custom_endpoint', 'custom-endpoint', 'customendpoint', 'custom_url', 'custom-url'] :
          name === 'model' ? ['data[model]', '"model"', "'model'", 'model'] :
          ['provider'];
        const hit = keys.some(k => ident.includes(k));
        if (!hit) return;
        if (name === 'model' && (ident.includes('operations') || ident.includes('context') || ident.includes('timeout') ||
            ident.includes('tokens') || ident.includes('key') || ident.includes('endpoint') ||
            ident.includes('embedding') || ident.includes('rag'))) return;
      }
      if (!out.includes(el)) out.push(el);
    };

    if (name === 'provider') {
      const sels = ['select[name="data[provider]"]', '[data-field="provider"] select', 'select[name="provider"]', '#provider', '#data\\[provider\\]'];
      for (const sel of sels) {
        for (const el of this._deepQueryOuter(sel)) pushIfValid(el, false);
      }
      if (out.length > 0) return out;
      // attribute-contains fallback for Svelte-rendered inputs
      for (const el of this._deepQueryOuter('select')) {
        const ident = this._candidateIdentity(el);
        if (ident.includes('provider')) pushIfValid(el, false);
      }
      if (out.length > 0) return out;
      // label-only fallback (never div/span: ancestor textContent causes filter-bar hits)
      for (const lbl of this._deepQueryOuter('label')) {
        const txt = (lbl.textContent || '').toLowerCase();
        if (txt.includes('ai provider engine') || txt.includes('select your preferred ai provider')) {
          const parent = lbl.closest('[data-field], .form-field, .field, .form-group') || lbl.parentElement;
          if (!parent) continue;
          const selsIn = this._deepQueryOuter('select', parent);
          if (selsIn.length > 3) continue; // too broad, likely ancestor container
          for (const el of selsIn) pushIfValid(el, false);
          if (out.length > 0) return out;
        }
      }
      return out;
    }

    const tag = name === 'provider' ? 'select' : 'input';
    const exactSels = name === 'api_key'
      ? ['input[name="data[api_key]"]', '[data-field="api_key"] input', '#data\\[api_key\\]', '#api_key']
      : name === 'custom_endpoint'
      ? ['input[name="data[custom_endpoint]"]', '[data-field="custom_endpoint"] input', '#data\\[custom_endpoint\\]', '#custom_endpoint']
      : ['input[name="data[model]"]', '[data-field="model"] input', '#data\\[model\\]', '#model'];
    for (const sel of exactSels) {
      for (const el of this._deepQueryOuter(sel)) {
        if (name === 'model') {
          const fieldAttr = (el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
          if (fieldAttr.includes('operations') || fieldAttr.includes('context') || fieldAttr.includes('timeout') ||
              fieldAttr.includes('tokens') || fieldAttr.includes('key') || fieldAttr.includes('endpoint') ||
              fieldAttr.includes('embedding') || fieldAttr.includes('rag')) continue;
        }
        pushIfValid(el, false);
      }
    }
    if (out.length > 0) return out;

    // attribute-contains fallback
    for (const el of this._deepQueryOuter(tag)) {
      pushIfValid(el, true);
    }
    if (out.length > 0) return out;

    // label-only fallback with strict container guard
    const matchLabel = (txt) => {
      if (name === 'api_key') return txt.includes('api key') || txt.includes('secret token');
      if (name === 'custom_endpoint') return txt.includes('custom url') || txt.includes('base endpoint');
      return txt === 'model identifier' || txt === 'model identifier *' ||
        (txt.includes('model identifier') && !txt.includes('context') && !txt.includes('tools'));
    };
    for (const lbl of this._deepQueryOuter('label')) {
      const txt = (lbl.textContent || '').toLowerCase().trim();
      if (!matchLabel(txt)) continue;
      const parent = lbl.closest('[data-field], .form-field, .field, .form-group') || lbl.parentElement;
      if (!parent) continue;
      const inps = this._deepQueryOuter('input', parent).filter(el => !this._isFilterInput(el));
      if (inps.length === 0 || inps.length > 3) continue; // broad ancestor guard
      const before = out.length;
      for (const el of inps) {
        if (name === 'model') {
          const fieldAttr = (el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase();
          if (fieldAttr.includes('operations') || fieldAttr.includes('context') || fieldAttr.includes('timeout') ||
              fieldAttr.includes('tokens') || fieldAttr.includes('key') || fieldAttr.includes('endpoint') ||
              fieldAttr.includes('embedding') || fieldAttr.includes('rag')) continue;
        }
        pushIfValid(el, true);
      }
      if (out.length > before) return out;
      // last resort: single unambiguous input in a tight container (fail closed otherwise)
      if (inps.length === 1) {
        const el = inps[0];
        if (name !== 'model' || !((el.closest('[data-field]')?.getAttribute('data-field') || '').toLowerCase().match(/operations|context|timeout|tokens|key|endpoint|embedding|rag/))) {
          pushIfValid(el, false);
        }
      }
      if (out.length > before) return out;
    }
    return out;
  }

  _findOuter(name) {
    const c = this._findOuterCandidates(name);
    return c.length > 0 ? c[0] : null;
  }

  _readOuter(name) {
    const el = this._findOuter(name);
    if (!el) return '';
    if (el.tagName === 'SELECT') {
      if (el.value) return el.value.trim().toLowerCase();
      if (el.selectedIndex >= 0 && el.options[el.selectedIndex]) {
        return (el.options[el.selectedIndex].value || '').trim().toLowerCase();
      }
      return '';
    }
    return (el.value || '').trim();
  }

  _writeOuter(name, val) {
    const candidates = this._findOuterCandidates(name);
    for (const el of candidates) {
      try {
        const proto = el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
        const nativeSetter = Object.getOwnPropertyDescriptor(proto, 'value')?.set;
        if (nativeSetter) nativeSetter.call(el, val);
        else el.value = val;
      } catch (e) {
        try { el.value = val; } catch (e2) { continue; }
      }
      el.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
      el.dispatchEvent(new Event('change', { bubbles: true, composed: true }));
      el.dispatchEvent(new Event('blur', { bubbles: true, composed: true }));
      return true;
    }
    return false;
  }

  _headers() {
    const headers = { 'Content-Type': 'application/json' };
    if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
      headers['X-API-Token'] = window.__GRAV_API_TOKEN;
    }
    return headers;
  }

  async _testApiKey() {
    const provider = this._provider || 'gemini';
    const apiKey = this._apiKey;
    const customEndpoint = this._customEndpoint;

    if (!apiKey && ['claude', 'deepseek', 'gemini', 'groq', 'openai', 'openrouter', 'reflection', 'xiaomi', 'zhipu'].includes(provider)) {
      this._status = { type: 'error', message: '❌ API Key is missing. Please enter your API Key before testing.' };
      this._render();
      return;
    }

    this._busy = true;
    this._status = { type: 'info', message: `⏳ Verifying API Key authentication against ${provider.toUpperCase()}...` };
    this._render();

    try {
      const res = await fetch('/api/v1/ai-chatbot/test-key?t=' + Date.now(), {
        method: 'POST',
        headers: this._headers(),
        body: JSON.stringify({ provider, api_key: apiKey, custom_endpoint: customEndpoint })
      });
      const data = await res.json();
      const payload = data.data || data;
      if (payload.success) {
        this._status = { type: 'success', message: payload.message };
      } else {
        this._status = { type: 'error', message: payload.message };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Error: ${err.message}` };
    }
    this._busy = false;
    this._render();
  }

  async _fetchModels() {
    const provider = this._provider || 'gemini';
    const apiKey = this._apiKey;
    const customEndpoint = this._customEndpoint;

    this._busy = true;
    this._status = { type: 'info', message: `⏳ Querying active model list from ${provider.toUpperCase()} API...` };
    this._render();

    try {
      const res = await fetch('/api/v1/ai-chatbot/models?t=' + Date.now(), {
        method: 'POST',
        headers: this._headers(),
        body: JSON.stringify({ provider, api_key: apiKey, custom_endpoint: customEndpoint })
      });
      const data = await res.json();
      const payload = data.data || data;
      if (payload.success && payload.models && payload.models.length) {
        this._models = payload.models.slice().sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
        this._isManualMode = false;
        if (!this._model) this._model = this._models[0];
        this._status = { type: 'success', message: `✅ Successfully retrieved ${payload.models.length} active models!` };
      } else {
        this._status = { type: 'error', message: `❌ ${payload.message || 'Failed to retrieve models'}` };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Error: ${err.message}` };
    }
    this._busy = false;
    this._render();
  }

  async _testModelHealth() {
    const provider = this._provider || 'gemini';
    const apiKey = this._apiKey;
    const model = this._model || this._testedModel;
    const customEndpoint = this._customEndpoint;

    if (!model) {
      this._status = { type: 'error', message: '❌ Please specify a Model Identifier to test.' };
      this._render();
      return;
    }

    this._testedModel = model;
    this._testedHealthSuccess = false;
    this._busy = true;
    this._status = { type: 'info', message: `⏳ Sending live health check ping to model '${model}' via ${provider.toUpperCase()}...` };
    this._render();

    try {
      const res = await fetch('/api/v1/ai-chatbot/health?t=' + Date.now(), {
        method: 'POST',
        headers: this._headers(),
        body: JSON.stringify({ provider, api_key: apiKey, model, custom_endpoint: customEndpoint })
      });
      const data = await res.json();
      const payload = data.data || data;
      if (payload.success) {
        this._testedHealthSuccess = true;
        this._status = { type: 'success', message: payload.message };
      } else {
        this._status = { type: 'error', message: payload.message };
      }
    } catch (err) {
      this._status = { type: 'error', message: `❌ Connection Error: ${err.message}` };
    }
    this._busy = false;
    this._render();
  }

  async _applyToConfig() {
    let copied = [];
    let skipped = [];
    if (this._provider) { if (this._writeOuter('provider', this._provider)) copied.push('provider'); else skipped.push('provider'); }
    // Allow conditional fields (e.g. custom_endpoint with show_on) to render after provider change.
    await new Promise(resolve => setTimeout(resolve, 80));
    if (this._apiKey) { if (this._writeOuter('api_key', this._apiKey)) { if (!copied.includes('api key')) copied.push('api key'); } else if (!skipped.includes('api key')) skipped.push('api key'); }
    if (this._customEndpoint) {
      // Retry a couple of times in case the field is still rendering.
      let ok = this._writeOuter('custom_endpoint', this._customEndpoint);
      if (!ok) { await new Promise(resolve => setTimeout(resolve, 120)); ok = this._writeOuter('custom_endpoint', this._customEndpoint); }
      if (ok && !copied.includes('endpoint URL')) copied.push('endpoint URL');
      else if (!ok && !skipped.includes('endpoint URL')) skipped.push('endpoint URL');
    } else if (this._touched.endpoint && !['ollama', 'omniroute'].includes(this._provider)) {
      // User explicitly cleared the field: wipe any stale override (e.g. leftover
      // Ollama URL) so the preset default is used again.
      if (this._writeOuter('custom_endpoint', '')) copied.push('endpoint URL (cleared)');
    }
    if (this._model) { if (this._writeOuter('model', this._model)) { if (!copied.includes('model')) copied.push('model'); } else if (!skipped.includes('model')) skipped.push('model'); }

    if (copied.length > 0) {
      let msg = `✅ Applied to configuration: ${copied.join(', ')}. Click Save to persist.`;
      if (skipped.length > 0) msg += ` Skipped (field not found): ${skipped.join(', ')}.`;
      this._status = { type: skipped.length > 0 ? 'info' : 'success', message: msg };
    } else {
      this._status = { type: 'error', message: '❌ Nothing to apply. Fill in at least one field.' };
    }
    this._render();
  }

  _syncFromConfig() {
    const outerProvider = this._readOuter('provider');
    const outerApiKey = this._readOuter('api_key');
    const outerEndpoint = this._readOuter('custom_endpoint');
    const outerModel = this._readOuter('model');
    if (outerProvider) this._provider = outerProvider;
    if (outerApiKey) this._apiKey = outerApiKey;
    if (outerEndpoint) this._customEndpoint = outerEndpoint;
    if (outerModel) this._model = outerModel;
    this._touched = { provider: false, apiKey: false, endpoint: false, model: false };
    this._status = { type: 'info', message: '🔄 Synced values from configuration fields.' };
    this._render();
  }

  _render() {
    const status = this._status;
    const provider = this._provider || 'gemini';
    const endpointRequired = ENDPOINT_REQUIRED.includes(provider);

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
        .section-title {
          font-size: 14px;
          font-weight: 700;
          color: #1e293b;
          margin-bottom: 4px;
        }
        .section-desc {
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
        .field-row {
          margin-bottom: 12px;
        }
        .field-row label {
          display: block;
          font-weight: 600;
          font-size: 12px;
          color: #334155;
          margin-bottom: 6px;
        }
        .field-row .hint {
          font-size: 11px;
          color: #94a3b8;
          margin-top: 4px;
        }
        select, input[type="text"], input[type="password"] {
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
        select:focus, input[type="text"]:focus, input[type="password"]:focus {
          outline: none;
          border-color: #4f46e5;
          box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .btn-group {
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
          align-items: center;
          margin-top: 8px;
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
        .btn-primary { background: #4f46e5; color: #ffffff; }
        .btn-primary:hover:not(:disabled) { background: #4338ca; }
        .btn-success { background: #059669; color: #ffffff; }
        .btn-success:hover:not(:disabled) { background: #047857; }
        .btn-warning { background: #d97706; color: #ffffff; }
        .btn-warning:hover:not(:disabled) { background: #b45309; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-secondary:hover:not(:disabled) { background: #e2e8f0; }
        .btn-apply {
          background: linear-gradient(135deg, #059669, #10b981);
          color: #ffffff;
          box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
        }
        .btn-apply:hover:not(:disabled) { background: linear-gradient(135deg, #047857, #059669); }
        .btn-toggle {
          background: #eff6ff;
          color: #1d4ed8;
          border: 1.5px solid #3b82f6;
          font-weight: 700;
          font-size: 12px;
          padding: 6px 12px;
          border-radius: 6px;
        }
        .btn-toggle:hover { background: #dbeafe; border-color: #2563eb; color: #1e40af; }
        .status {
          margin-top: 14px;
          padding: 12px 14px;
          border-radius: 6px;
          font-size: 13px;
          font-weight: 600;
          word-break: break-word;
        }
        .status.info { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .status.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .model-select-box { margin-top: 12px; margin-bottom: 14px; }
        .model-select-box label {
          display: block;
          font-weight: 600;
          font-size: 12px;
          color: #334155;
          margin-bottom: 6px;
        }
        .divider {
          height: 1px;
          background: #f1f5f9;
          margin: 16px 0;
        }
      </style>

      <div class="wizard-container">
        <div class="section-title">🛠️ Connection & Model Tester</div>
        <div class="section-desc">Test your provider connection, fetch available models, and run a live health check — without saving configuration first.</div>
        <div class="note-hint">ℹ️ Values are tested directly. Use "Apply to Configuration" to copy them into the form fields above, then Save.</div>

        <div class="field-row">
          <label for="w-provider">🤖 AI Provider Engine</label>
          <select id="w-provider">
            ${PROVIDERS.map(p => `<option value="${p.value}" ${p.value === provider ? 'selected' : ''}>${esc(p.label)}</option>`).join('')}
          </select>
        </div>

        <div class="field-row">
          <label for="w-apikey">🔑 API Key</label>
          <input type="password" id="w-apikey" placeholder="Enter API Key or Secret Token..." value="${esc(this._apiKey)}" autocomplete="off" />
          <div class="hint">Optional for local endpoints (Ollama).</div>
        </div>

        <div class="field-row">
          <label for="w-endpoint">🌐 Endpoint URL ${endpointRequired ? '(required)' : '(optional override)'}</label>
          <input type="text" id="w-endpoint" placeholder="https://gateway.example.com/v1" value="${esc(this._customEndpoint)}" />
          <div class="hint">${endpointRequired ? 'Required for this provider. Paste the base URL only, e.g. https://gateway.example.com/v1. Paths (/chat/completions, /models) are appended automatically.' : 'Optional override for any preset. Paste the base URL only, e.g. https://gateway.example.com/v1. Paths (/chat/completions, /models) are appended automatically.'}</div>
        </div>

        <div class="btn-group">
          <button type="button" id="btn-test-key" class="btn-warning" ${this._busy ? 'disabled' : ''}>🔑 Test Connection</button>
          <button type="button" id="btn-fetch-models" class="btn-primary" ${this._busy ? 'disabled' : ''}>🔄 Get Models</button>
          <button type="button" id="btn-sync" class="btn-secondary" ${this._busy ? 'disabled' : ''}>🔄 Sync from Config</button>
        </div>

        ${status.message ? `<div class="status ${status.type}">${esc(status.message)}</div>` : ''}

        <div class="divider"></div>

        <div class="section-title">Step: Select Model & Health Check</div>
        <div class="section-desc">Choose a retrieved model or type an unlisted model ID, then run a live health ping.</div>

        <div class="model-select-box">
          ${!this._isManualMode && this._models.length > 0 ? `
            <label>Active Provider Models (${this._models.length} retrieved):</label>
            <select id="w-model">
              ${this._models.map(m => `<option value="${esc(m)}" ${m === (this._model || this._testedModel) ? 'selected' : ''}>${esc(m)}</option>`).join('')}
            </select>
            <div style="margin-top:8px;">
              <button type="button" id="btn-toggle-manual" class="btn-toggle">✍️ Switch to Unlisted / Custom Model Input</button>
            </div>
          ` : `
            <label>Enter Custom / Unlisted Model ID:</label>
            <input type="text" id="w-model" placeholder="e.g. gemini-3.1-flash-lite, gpt-4o-mini, llama3.3" value="${esc(this._model || this._testedModel || '')}" />
            ${this._models.length > 0 ? `
              <div style="margin-top:8px;">
                <button type="button" id="btn-toggle-select" class="btn-toggle">📋 Choose from Retrieved Models (${this._models.length})</button>
              </div>
            ` : ''}
          `}
        </div>

        <div class="btn-group">
          <button type="button" id="btn-test-health" class="btn-success" ${this._busy ? 'disabled' : ''}>⚡ Test Model Health</button>
          <button type="button" id="btn-apply" class="btn-apply" ${this._busy ? 'disabled' : ''}>✨ Apply to Configuration</button>
        </div>
      </div>
    `;

    const root = this.shadowRoot;

    // Inputs
    const providerEl = root.getElementById('w-provider');
    if (providerEl) {
      providerEl.addEventListener('change', () => {
        this._provider = providerEl.value;
        this._touched.provider = true;
        this._render();
      });
    }
    const apiKeyEl = root.getElementById('w-apikey');
    if (apiKeyEl) {
      apiKeyEl.addEventListener('input', () => { this._apiKey = apiKeyEl.value; this._touched.apiKey = true; });
      apiKeyEl.addEventListener('change', () => { this._apiKey = apiKeyEl.value; this._touched.apiKey = true; });
    }
    const endpointEl = root.getElementById('w-endpoint');
    if (endpointEl) {
      endpointEl.addEventListener('input', () => { this._customEndpoint = endpointEl.value; this._touched.endpoint = true; });
      endpointEl.addEventListener('change', () => { this._customEndpoint = endpointEl.value; this._touched.endpoint = true; });
    }
    const modelEl = root.getElementById('w-model');
    if (modelEl) {
      const syncModel = () => { this._model = modelEl.value; this._testedModel = modelEl.value; this._touched.model = true; };
      modelEl.addEventListener('input', syncModel);
      modelEl.addEventListener('change', syncModel);
    }

    // Buttons
    root.getElementById('btn-test-key')?.addEventListener('click', () => this._testApiKey());
    root.getElementById('btn-fetch-models')?.addEventListener('click', () => this._fetchModels());
    root.getElementById('btn-sync')?.addEventListener('click', () => this._syncFromConfig());
    root.getElementById('btn-toggle-manual')?.addEventListener('click', () => { this._isManualMode = true; this._render(); });
    root.getElementById('btn-toggle-select')?.addEventListener('click', () => { this._isManualMode = false; this._render(); });
    root.getElementById('btn-test-health')?.addEventListener('click', () => {
      const sel = root.getElementById('w-model');
      const val = sel ? sel.value : (this._model || '');
      if (val) { this._model = val; this._testedModel = val; }
      this._testModelHealth();
    });
    root.getElementById('btn-apply')?.addEventListener('click', () => this._applyToConfig());
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, ChatbotModelTools);
}
