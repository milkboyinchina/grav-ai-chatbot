const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-live-logs';

class ChatbotLiveLogs extends HTMLElement {
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
    this._fetchLogs();
    this._pollInterval = setInterval(() => this._fetchLogs(), 8000);
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
        .container { box-sizing: border-box; width: 100%; max-width: 100%; background: #090d16; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 16px; color: #e2e8f0; font-family: ui-monospace, monospace; margin: 8px 0 16px 0; overflow: hidden; }
        .header { box-sizing: border-box; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); flex-wrap: wrap; gap: 8px; }
        .title { font-weight: 600; font-size: 0.9rem; color: #38bdf8; }
        .body { box-sizing: border-box; width: 100%; max-width: 100%; background: #020617; border-radius: 8px; padding: 12px; height: 220px; overflow-y: auto; font-size: 0.75rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; color: #94a3b8; }
        button { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.4); border-radius: 6px; padding: 4px 10px; font-size: 0.7rem; cursor: pointer; }
      </style>
      <div class="container">
        <div class="header">
          <div class="title">📜 AI Chatbot Live Streaming Error &amp; Audit Log</div>
          <button type="button" id="btn-clear">Clear Feed</button>
        </div>
        <div class="body" id="logs-output">Loading live log stream...</div>
      </div>
    `;
    const clearBtn = this.shadowRoot.querySelector('#btn-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        const out = this.shadowRoot.querySelector('#logs-output');
        if (out) out.textContent = 'Log stream cleared.';
        this._value = '';
        this._emitChange('');
      });
    }
  }

  async _fetchLogs() {
    try {
      const headers = {};
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/logs?per_page=20&t=' + Date.now(), { headers });
      if (!res.ok) return;
      const json = await res.json();
      const items = json.data || json.logs || [];
      const out = this.shadowRoot ? this.shadowRoot.querySelector('#logs-output') : null;
      if (out) {
        if (Array.isArray(items) && items.length > 0) {
          out.textContent = items.map((e) => {
            if (typeof e === 'string') return e;
            return `[${e.timestamp || ''}] [${e.source || ''}] Q: ${e.question || ''}`;
          }).join('\n');
        } else if (json.data && json.data.logs) {
          out.textContent = String(json.data.logs).trim() || 'No log entries recorded yet.';
        } else {
          out.textContent = 'No log entries recorded yet.';
        }
        out.scrollTop = out.scrollHeight;
      }
    } catch (e) {
      // Silence network glitches during live polling
    }
  }
}

try {
  if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
    customElements.define(TAG, ChatbotLiveLogs);
  }
} catch (e) {
  // Ignore duplicate registration
}
