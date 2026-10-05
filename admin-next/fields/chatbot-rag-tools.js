const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-rag-tools';

class ChatbotRagTools extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._field = null;
    this._value = '';
    this._status = { type: '', message: '' };
    this._busy = false;
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
  }

  _emitChange(v) {
    this.dispatchEvent(new CustomEvent('change', { detail: v, bubbles: true, composed: true }));
  }

  async _rebuild() {
    if (this._busy) return;
    this._busy = true;
    this._status = { type: 'info', message: '⏳ Rebuilding RAG Vector Index... Please wait.' };
    this._render();
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/reindex', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({})
      });
      const json = await res.json();
      const payload = json.data || json;
      this._busy = false;
      if (payload.success) {
        this._status = { type: 'success', message: '✅ ' + (payload.message || 'RAG index rebuilt.') };
      } else {
        this._status = { type: 'error', message: '❌ Indexing Error: ' + (payload.error || payload.message || 'Failed') };
      }
    } catch (err) {
      this._busy = false;
      this._status = { type: 'error', message: '❌ Connection Error: ' + err.message };
    }
    this._render();
  }

  _render() {
    if (!this.shadowRoot) return;
    const s = this._status;
    this.shadowRoot.innerHTML = `
      <style>
        :host { display: block; width: 100%; max-width: 100%; box-sizing: border-box; }
        .box { box-sizing: border-box; width: 100%; max-width: 100%; margin: 8px 0 16px 0; }
        button { background: #10b981; color: #fff; font-weight: 600; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        .status { margin-top: 10px; padding: 10px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; word-break: break-word; }
        .status.info { background: #f3f4f6; color: #1f2937; }
        .status.success { background: #d1fae5; color: #065f46; }
        .status.error { background: #fee2e2; color: #991b1b; }
      </style>
      <div class="box">
        <button type="button" id="btn-rebuild" ${this._busy ? 'disabled' : ''}>⚡ Rebuild RAG Vector Index Now</button>
        ${s.message ? `<div class="status ${s.type}">${s.message}</div>` : ''}
      </div>
    `;
    const btn = this.shadowRoot.querySelector('#btn-rebuild');
    if (btn) btn.addEventListener('click', () => this._rebuild());
  }
}

try {
  if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
    customElements.define(TAG, ChatbotRagTools);
  }
} catch (e) {
  // Ignore duplicate registration
}
