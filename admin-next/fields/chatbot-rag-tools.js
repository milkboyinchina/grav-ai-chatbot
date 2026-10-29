const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-rag-tools';

class ChatbotRagTools extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._field = null;
    this._value = '';
    this._status = { type: '', message: '' };
    this._busy = false;
    this._indexStatus = null;
    this._embedStatus = null;
    this._embedBusy = false;
    this._indexedPages = null;
    this._showIndexedPages = false;
    this._indexedPagesLoading = false;
    this._embedModels = [];
    this._embedModelsLoading = false;
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
    try {
      this._fetchStatus();
      this._render();
    } catch (err) {
      console.error('chatbot-rag-tools connectedCallback error:', err);
    }
  }

  async _fetchStatus() {
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/rag-status?t=' + Date.now(), { headers });
      const json = await res.json();
      const payload = json.data || json;
      if (payload.success && payload.status) {
        this._indexStatus = payload.status;
        this._render();
      }
    } catch (e) {
      // ignore
    }
  }

  _emitChange(v) {
    this.dispatchEvent(new CustomEvent('change', { detail: v, bubbles: true, composed: true }));
  }

  _isFilterInput(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    const t = (el.type || '').toLowerCase();
    return t === 'search' || t === 'text' && /filter|search/i.test(el.placeholder || '');
  }

  _findOuterCandidates(labelPrefixes) {
    const out = {};
    const prefixToKey = {
      'RAG Embedding': 'rag_embedding_provider',
      'Embedding Model': 'rag_embedding_model',
      'Embedding API': 'rag_embedding_api_key',
      'Embedding Endpoint': 'rag_embedding_endpoint',
      'Custom URL': 'custom_endpoint'
    };
    // Admin2 Svelte rows: div.grid > [label div, field div]. No name/id on inputs.
    const tests = {
      'RAG Embedding': (t) => t.includes('rag embedding') || t.includes('embedding provider'),
      'Embedding Model': (t) => t.includes('embedding model'),
      'Embedding API': (t) => t.includes('embedding api'),
      'Embedding Endpoint': (t) => t.includes('embedding endpoint'),
      'Custom URL': (t) => t.includes('custom url') || t.includes('custom endpoint')
    };
    let grids = [];
    try { grids = Array.from(document.querySelectorAll('div.grid')); } catch (e) { grids = []; }
    for (const g of grids) {
      if (this.contains && this.contains(g)) continue;
      const kids = g.children;
      if (!kids || kids.length < 2) continue;
      let lab = '';
      try { lab = (kids[0].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase().replace(/^[^a-z0-9]+/, '').trim(); } catch (e) { continue; }
      const fieldBox = kids[1];
      for (const p of labelPrefixes) {
        if (out[p]) continue;
        const test = tests[p];
        if (!test || !test(lab)) continue;
        const wantTag = (p === 'RAG Embedding') ? 'select' : 'input';
        let inputs = [];
        try { inputs = Array.from(fieldBox.querySelectorAll(wantTag === 'select' ? 'select' : 'input')); } catch (e) { continue; }
        for (const el of inputs) {
          if (this._isFilterInput(el)) continue;
          out[p] = el;
          break;
        }
      }
    }
    // Fallback: match by input/select name or data-field-name containing the field key
    for (const p of labelPrefixes) {
      if (out[p]) continue;
      const key = prefixToKey[p];
      if (!key) continue;
      const el = Array.from(document.querySelectorAll('input, select, textarea')).find(e => {
        if (this._isFilterInput(e)) return false;
        const nm = (e.name || e.getAttribute('data-field-name') || e.getAttribute('aria-label') || e.id || '').toLowerCase();
        return nm.includes(key);
      });
      if (el) out[p] = el;
    }
    return out;
  }

  async _fetchEmbeddingModels() {
    if (this._embedModelsLoading) return;
    this._embedModelsLoading = true;
    this._render();
    let cands = this._findOuterCandidates(['RAG Embedding', 'Embedding Model', 'Embedding API', 'Embedding Endpoint', 'Custom URL']);
    if (!cands['RAG Embedding']) {
      await new Promise(r => setTimeout(r, 150));
      cands = this._findOuterCandidates(['RAG Embedding', 'Embedding Model', 'Embedding API', 'Embedding Endpoint', 'Custom URL']);
    }
    const provider = cands['RAG Embedding'] ? cands['RAG Embedding'].value : '';
    const apiKey = cands['Embedding API'] ? cands['Embedding API'].value : '';
    const endpoint = cands['Embedding Endpoint'] ? cands['Embedding Endpoint'].value : (cands['Custom URL'] ? cands['Custom URL'].value : '');
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/models', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          rag_embedding_provider: provider,
          api_key: apiKey,
          custom_endpoint: endpoint,
          embedding: true
        })
      });
      const json = await res.json();
      const payload = json.data || json;
      this._embedModelsLoading = false;
      if (payload.success && Array.isArray(payload.models)) {
        this._embedModels = payload.models;
        this._status = { type: 'success', message: '✅ Fetched ' + payload.models.length + ' embedding models.' };
      } else {
        this._embedModels = [];
        this._status = { type: 'error', message: '❌ ' + (payload.message || payload.error || 'Failed to fetch models') };
      }
    } catch (err) {
      this._embedModelsLoading = false;
      this._embedModels = [];
      this._status = { type: 'error', message: '❌ ' + err.message };
    }
    this._render();
  }

  _applyEmbeddingModel(name) {
    if (!name) return;
    const cands = this._findOuterCandidates(['Embedding Model']);
    const el = cands['Embedding Model'];
    if (el) {
      el.value = name;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  async _testEmbedding() {
    if (this._embedBusy) return;
    this._embedBusy = true;
    this._embedStatus = { type: 'info', message: '⏳ Testing embedding...' };
    this._render();
    let cands = this._findOuterCandidates(['RAG Embedding', 'Embedding Model', 'Embedding API', 'Embedding Endpoint', 'Custom URL']);
    if (!cands['RAG Embedding'] && !cands['Embedding Model']) {
      await new Promise(r => setTimeout(r, 150));
      cands = this._findOuterCandidates(['RAG Embedding', 'Embedding Model', 'Embedding API', 'Embedding Endpoint', 'Custom URL']);
    }
    const provider = cands['RAG Embedding'] ? cands['RAG Embedding'].value : '';
    const model = cands['Embedding Model'] ? cands['Embedding Model'].value : '';
    const apiKey = cands['Embedding API'] ? cands['Embedding API'].value : '';
    const endpoint = cands['Embedding Endpoint'] ? cands['Embedding Endpoint'].value : (cands['Custom URL'] ? cands['Custom URL'].value : '');
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/test-embedding', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          rag_embedding_provider: provider,
          rag_embedding_model: model,
          rag_embedding_api_key: apiKey,
          rag_embedding_endpoint: endpoint,
          custom_endpoint: endpoint
        })
      });
      const json = await res.json();
      const payload = json.data || json;
      this._embedBusy = false;
      if (payload.success) {
        let msg = '✅ ' + (payload.message || 'Embedding OK');
        if (payload.note) msg += ' — ' + payload.note;
        this._embedStatus = { type: 'success', message: msg };
      } else {
        this._embedStatus = { type: 'error', message: '❌ ' + (payload.message || payload.error || 'Failed') };
      }
    } catch (err) {
      this._embedBusy = false;
      this._embedStatus = { type: 'error', message: '❌ ' + err.message };
    }
    this._render();
  }

  async _rebuild() {
    if (this._busy) return;
    const ok = (typeof window !== 'undefined' && typeof window.confirm === 'function')
      ? window.confirm('This will DELETE the entire RAG vector index and re-embed every chunk from scratch. It can take several minutes on large sites. Continue?')
      : true;
    if (!ok) return;
    this._busy = true;
    const start = Date.now();
    this._status = { type: 'info', message: '⏳ Rebuilding RAG Vector Index... Please wait.' };
    this._render();
    const timer = setInterval(() => {
      const secs = Math.floor((Date.now() - start) / 1000);
      this._status = { type: 'info', message: '⏳ Rebuilding RAG Vector Index... ' + secs + 's elapsed. Please wait.' };
      this._render();
    }, 1000);
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
      clearInterval(timer);
      if (payload.success) {
        this._status = { type: 'success', message: '✅ ' + (payload.message || 'RAG index rebuilt.') };
        this._fetchStatus();
        if (this._showIndexedPages) {
          this._indexedPages = null;
          await this._refreshIndexedPages();
        }
      } else {
        this._status = { type: 'error', message: '❌ Indexing Error: ' + (payload.error || payload.message || 'Failed') };
      }
    } catch (err) {
      this._busy = false;
      clearInterval(timer);
      this._status = { type: 'error', message: '❌ Connection Error: ' + err.message };
    }
    this._render();
  }

  async _incremental() {
    if (this._busy) return;
    this._busy = true;
    const start = Date.now();
    this._status = { type: 'info', message: '⏳ Incrementally updating RAG index... Please wait.' };
    this._render();
    const timer = setInterval(() => {
      const secs = Math.floor((Date.now() - start) / 1000);
      this._status = { type: 'info', message: '⏳ Incrementally updating RAG index... ' + secs + 's elapsed. Please wait.' };
      this._render();
    }, 1000);
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/reindex-incremental', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({})
      });
      const json = await res.json();
      const payload = json.data || json;
      this._busy = false;
      clearInterval(timer);
      if (payload.success) {
        this._status = { type: 'success', message: '✅ ' + (payload.message || 'RAG index updated.') + ' (Indexed: ' + (payload.indexed_chunks ?? 0) + ', Skipped: ' + (payload.skipped_chunks ?? 0) + ', Total: ' + (payload.total_chunks ?? 0) + ')' };
        this._fetchStatus();
        if (this._showIndexedPages) {
          await this._refreshIndexedPages();
        }
      } else {
        this._status = { type: 'error', message: '❌ Indexing Error: ' + (payload.error || payload.message || 'Failed') };
      }
    } catch (err) {
      this._busy = false;
      clearInterval(timer);
      this._status = { type: 'error', message: '❌ Connection Error: ' + err.message };
    }
    this._render();
  }

  async _toggleIndexedPages() {
    this._showIndexedPages = !this._showIndexedPages;
    if (this._showIndexedPages && !this._indexedPages) {
      this._indexedPagesLoading = true;
      this._render();
      try {
        const headers = { 'Content-Type': 'application/json' };
        if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
          headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        }
        const res = await fetch('/api/v1/ai-chatbot/rag-pages?t=' + Date.now(), { headers });
        const json = await res.json();
        const payload = json.data || json;
        if (payload.success && Array.isArray(payload.pages)) {
          this._indexedPages = payload.pages;
        }
      } catch (e) {
        this._indexedPages = [];
      }
      this._indexedPagesLoading = false;
    }
    this._render();
  }

  async _refreshIndexedPages() {
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }
      const res = await fetch('/api/v1/ai-chatbot/rag-pages?t=' + Date.now(), { headers });
      const json = await res.json();
      const payload = json.data || json;
      if (payload.success && Array.isArray(payload.pages)) {
        this._indexedPages = payload.pages;
      }
    } catch (e) {
      // ignore
    }
    this._render();
  }

  _render() {
    if (!this.shadowRoot) return;
    const s = this._status;
    try {
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
        ${this._indexStatus ? `<div class="status info" style="margin-bottom:10px;">Chunks: ${this._indexStatus.chunk_count ?? 0} | Storage: ${this._indexStatus.db_size_human ?? (this._indexStatus.db_size_bytes ? (this._indexStatus.db_size_bytes / 1048576).toFixed(2) + ' MB' : '0 B')} | Provider: ${this._indexStatus.embedding_provider ?? ''} | Model: ${this._indexStatus.embedding_model ?? ''} | Last run: ${this._indexStatus.last_run ? new Date(this._indexStatus.last_run * 1000).toLocaleString() : 'never'}${this._indexStatus.last_error ? ' | Error: ' + String(this._indexStatus.last_error).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) : ''}</div>` : ''}
        ${this._indexStatus ? `<div class="status info" style="margin-bottom:10px;">Auto-indexing: ${this._indexStatus.indexing_enabled ? 'Enabled' : 'Disabled'} | Scheduler: ${this._indexStatus.scheduler_enabled ? 'Enabled' : 'Disabled'}${this._indexStatus.scheduler_enabled ? ' (' + (this._indexStatus.scheduler_cron || '') + ')' : ''}</div>` : ''}
        ${this._indexStatus && this._indexStatus.embedding_provider && this._indexStatus.embedding_model ? this._staleWarning(this._indexStatus) : ''}
        <button type="button" id="btn-fetch-embed-models" ${this._embedModelsLoading ? 'disabled' : ''} style="background:#7c3aed;margin-right:8px;">🔍 Fetch Embedding Models</button>
        <button type="button" id="btn-test-embed" ${this._embedBusy ? 'disabled' : ''} style="background:#2563eb;margin-right:8px;">🧪 Test Embedding</button>
        <button type="button" id="btn-incremental" ${this._busy ? 'disabled' : ''} style="background:#0ea5e9;margin-right:8px;">🔄 Incremental Index Update</button>
        <button type="button" id="btn-rebuild" ${this._busy ? 'disabled' : ''} style="background:#ef4444;">⚡ Rebuild RAG Vector Index Now</button>
        <button type="button" id="btn-toggle-pages" ${this._busy ? 'disabled' : ''} style="background:#6b7280;margin-left:8px;margin-top:8px;">${this._showIndexedPages ? 'Hide' : 'Show'} indexed pages${this._indexedPages ? ' (' + this._indexedPages.length + ')' : ''}</button>
        ${this._embedModels && this._embedModels.length ? `<div style="margin-top:8px;"><label style="font-size:12px;font-weight:600;">Embedding models:</label> <select id="sel-embed-model" style="padding:4px 8px;border-radius:4px;border:1px solid #d1d5db;font-size:12px;"><option value="">-- select to apply --</option>${this._embedModels.map(m => `<option value="${String(m).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}">${String(m).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`).join('')}</select></div>` : ''}
        ${s.message ? `<div class="status ${s.type}">${s.message}</div>` : ''}
        ${this._embedStatus && this._embedStatus.message ? `<div class="status ${this._embedStatus.type}">${this._embedStatus.message}</div>` : ''}
      </div>
    `;
    } catch (err) {
      console.error('chatbot-rag-tools render error:', err);
      if (this.shadowRoot) this.shadowRoot.innerHTML = '<div style="color:#991b1b;padding:8px;">Render error: ' + String(err && err.message ? err.message : err) + '</div>';
      return;
    }
    const btnRebuild = this.shadowRoot.querySelector('#btn-rebuild');
    if (btnRebuild) btnRebuild.addEventListener('click', () => this._rebuild());
    const btnIncremental = this.shadowRoot.querySelector('#btn-incremental');
    if (btnIncremental) btnIncremental.addEventListener('click', () => this._incremental());
    const btnFetchEmbed = this.shadowRoot.querySelector('#btn-fetch-embed-models');
    if (btnFetchEmbed) btnFetchEmbed.addEventListener('click', () => this._fetchEmbeddingModels());
    const btnTest = this.shadowRoot.querySelector('#btn-test-embed');
    if (btnTest) btnTest.addEventListener('click', () => this._testEmbedding());
    const btnTogglePages = this.shadowRoot.querySelector('#btn-toggle-pages');
    if (btnTogglePages) btnTogglePages.addEventListener('click', () => this._toggleIndexedPages());
    const selEmbed = this.shadowRoot.querySelector('#sel-embed-model');
    if (selEmbed) selEmbed.addEventListener('change', (e) => this._applyEmbeddingModel(e.target.value));
  }

  _staleWarning(status) {
    // Compare stored build provider/model with current outer fields.
    const cands = this._findOuterCandidates(['RAG Embedding', 'Embedding Model']);
    const curProvider = cands['RAG Embedding'] ? (cands['RAG Embedding'].value || '').toLowerCase() : '';
    const curModel = cands['Embedding Model'] ? (cands['Embedding Model'].value || '').toLowerCase() : '';
    const builtProvider = (status.embedding_provider || '').toLowerCase();
    const builtModel = (status.embedding_model || '').toLowerCase();
    if (curProvider && builtProvider && (curProvider !== builtProvider || (curModel && builtModel && curModel !== builtModel))) {
      return `<div class="status error">⚠️ Embedding settings changed since last index (built with ${builtProvider} / ${builtModel}). Rebuild required.</div>`;
    }
    return '';
  }

}

try {
  if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
    customElements.define(TAG, ChatbotRagTools);
  }
} catch (e) {
  // Ignore duplicate registration
}
