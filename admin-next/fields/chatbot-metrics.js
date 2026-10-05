const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-metrics';

class ChatbotMetrics extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._field = null;
    this._value = '';
    this._pollInterval = null;
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

  connectedCallback() {
    this._render();
    this._fetchMetrics();
    this._pollInterval = setInterval(() => this._fetchMetrics(), 15000);
  }

  disconnectedCallback() {
    if (this._pollInterval) {
      clearInterval(this._pollInterval);
      this._pollInterval = null;
    }
  }

  _emitChange(v) {
    this.dispatchEvent(new CustomEvent('change', { detail: v, bubbles: true, composed: true }));
  }

  _render() {
    if (!this.shadowRoot) return;
    this.shadowRoot.innerHTML = `
      <style>
        :host { display: block; width: 100%; max-width: 100%; box-sizing: border-box; }
        .container { box-sizing: border-box; width: 100%; max-width: 100%; background: rgba(15,23,42,0.85); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 16px; color: #f8fafc; font-family: system-ui, sans-serif; margin: 8px 0 16px 0; overflow: hidden; }
        .grid { box-sizing: border-box; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; width: 100%; }
        .card { box-sizing: border-box; background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
        .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; font-weight: 600; }
        .val { font-size: 1.4rem; font-weight: 700; color: #38bdf8; }
        .sub { font-size: 0.7rem; color: #64748b; }
        .header { box-sizing: border-box; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.08); flex-wrap: wrap; gap: 8px; }
        .title { font-weight: 600; font-size: 0.95rem; color: #e2e8f0; }
        .badge { background: #10b981; color: #064e3b; font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; }
      </style>
      <div class="container">
        <div class="header">
          <div class="title">📊 Real-Time AI Chatbot Metrics <span class="badge">Grav 2.0 API</span></div>
          <div style="font-size:0.75rem;color:#94a3b8;" id="updated">Updating...</div>
        </div>
        <div class="grid">
          <div class="card"><span class="label">💬 Total Conversations</span><span class="val" id="m-total">0</span><span class="sub">All time</span></div>
          <div class="card"><span class="label">🔤 Output Tokens</span><span class="val" id="m-tokens" style="color:#a78bfa;">0</span><span class="sub">Generated Tokens</span></div>
          <div class="card"><span class="label">💰 Estimated API Cost</span><span class="val" id="m-cost" style="color:#34d399;">$0.0000</span><span class="sub">Token Expenditure</span></div>
          <div class="card"><span class="label">🎯 Active AI Provider</span><span class="val" id="m-provider" style="font-size:1.1rem;color:#f43f5e;">-</span><span class="sub" id="m-model">Model</span></div>
        </div>
      </div>
    `;
  }

  _set(id, text) {
    const el = this.shadowRoot ? this.shadowRoot.querySelector('#' + id) : null;
    if (el) el.textContent = text;
  }

  async _fetchMetrics() {
    try {
      const headers = {};
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/metrics?t=' + Date.now(), { headers });
      if (!res.ok) return;
      const json = await res.json();
      const payload = json.data || json;
      if (payload && (payload.success !== false)) {
        const stats = payload.stats || payload.summary || {};
        this._set('m-total', (stats.total_queries || 0).toLocaleString());
        this._set('m-tokens', (stats.completion_tokens || stats.total_tokens || 0).toLocaleString());
        const cost = stats.estimated_cost || stats.total_cost_usd || 0;
        this._set('m-cost', '$' + Number(cost).toFixed(4));
        this._set('m-provider', String(payload.provider || 'OmniRoute').toUpperCase());
        this._set('m-model', payload.model || 'chatbot');
        const upd = this.shadowRoot ? this.shadowRoot.querySelector('#updated') : null;
        if (upd) upd.textContent = 'Updated ' + new Date().toLocaleTimeString();
      }
    } catch (e) {
      // Silence network glitches during live polling
    }
  }
}

try {
  if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
    customElements.define(TAG, ChatbotMetrics);
  }
} catch (e) {
  // Ignore duplicate registration
}
