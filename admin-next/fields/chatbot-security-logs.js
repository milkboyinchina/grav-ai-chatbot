const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-security-logs';

class ChatbotSecurityLogs extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._data = {
      threat_level: 'secure',
      active_lockouts: 0,
      total_violations: 0,
      logs: []
    };
  }

  connectedCallback() {
    this._render();
    this._fetchData();
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

  async _fetchData() {
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }

      const res = await fetch('/chatbot-api', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({ action: 'get_security_logs' })
      });

      if (res.ok) {
        const json = await res.json();
        if (json.success && json.data) {
          this._data = json.data;
          this._render();
        }
      }
    } catch (e) {
      console.warn('ChatbotSecurityLogs: Error fetching security audit logs', e);
    }
  }

  async _handleAction(actionName) {
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
        headers['X-API-Token'] = window.__GRAV_API_TOKEN;
      }

      const res = await fetch('/chatbot-api', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({ action: actionName })
      });

      if (res.ok) {
        await this._fetchData();
      }
    } catch (e) {
      console.error('ChatbotSecurityLogs: Action error', e);
    }
  }

  _render() {
    if (!this.shadowRoot) return;

    const logs = this._data.logs || [];
    const lockouts = this._data.active_lockouts || 0;
    const total = this._data.total_violations || 0;
    const isSecure = lockouts === 0;

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          width: 100%;
          max-width: 100%;
          box-sizing: border-box;
          font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .card {
          background: var(--background-secondary, #1e1e2d);
          color: var(--foreground, #e0e0e0);
          border: 1px solid var(--border-color, #2b2b3d);
          border-radius: 8px;
          padding: 1.25rem;
          box-sizing: border-box;
          width: 100%;
          max-width: 100%;
        }

        .header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 1rem;
          flex-wrap: wrap;
          gap: 0.5rem;
        }

        .title {
          font-size: 1.1rem;
          font-weight: 700;
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }

        .badge {
          padding: 0.25rem 0.6rem;
          border-radius: 12px;
          font-size: 0.8rem;
          font-weight: 600;
        }

        .badge.secure {
          background: rgba(46, 204, 113, 0.2);
          color: #2ecc71;
          border: 1px solid rgba(46, 204, 113, 0.4);
        }

        .badge.warning {
          background: rgba(231, 76, 60, 0.2);
          color: #e74c3c;
          border: 1px solid rgba(231, 76, 60, 0.4);
        }

        .actions {
          display: flex;
          gap: 0.5rem;
        }

        .btn {
          background: var(--button-bg, #3b82f6);
          color: #fff;
          border: none;
          padding: 0.4rem 0.8rem;
          border-radius: 4px;
          font-size: 0.85rem;
          font-weight: 600;
          cursor: pointer;
          transition: opacity 0.2s ease;
        }

        .btn:hover {
          opacity: 0.9;
        }

        .btn.outline {
          background: transparent;
          border: 1px solid var(--border-color, #444);
          color: var(--foreground, #ddd);
        }

        .btn.outline:hover {
          background: rgba(255, 255, 255, 0.05);
        }

        .stats-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
          gap: 0.75rem;
          margin-bottom: 1.25rem;
        }

        .stat-box {
          background: var(--background-tertiary, #141421);
          border: 1px solid var(--border-color, #2b2b3d);
          border-radius: 6px;
          padding: 0.75rem 1rem;
        }

        .stat-value {
          font-size: 1.4rem;
          font-weight: 700;
          margin-top: 0.2rem;
        }

        .stat-label {
          font-size: 0.75rem;
          text-transform: uppercase;
          letter-spacing: 0.5px;
          color: #888;
        }

        .table-container {
          width: 100%;
          max-width: 100%;
          overflow-x: auto;
          border: 1px solid var(--border-color, #2b2b3d);
          border-radius: 6px;
        }

        table {
          width: 100%;
          border-collapse: collapse;
          text-align: left;
          font-size: 0.85rem;
        }

        th {
          background: var(--background-tertiary, #141421);
          padding: 0.6rem 0.8rem;
          font-weight: 600;
          color: #aaa;
          border-bottom: 1px solid var(--border-color, #2b2b3d);
        }

        td {
          padding: 0.6rem 0.8rem;
          border-bottom: 1px solid var(--border-color, #222233);
          word-break: break-word;
        }

        tr:last-child td {
          border-bottom: none;
        }

        .empty-state {
          padding: 1.5rem;
          text-align: center;
          color: #888;
          font-style: italic;
        }
      </style>

      <div class="card">
        <div class="header">
          <div class="title">
            🛡️ AI Security & Threat Audit Log
            <span class="badge ${isSecure ? 'secure' : 'warning'}">
              ${isSecure ? '🟢 System Secure' : `⚠️ ${lockouts} Active Lockout(s)`}
            </span>
          </div>

          <div class="actions">
            <button class="btn outline" id="btn-refresh">🔄 Refresh</button>
            <button class="btn outline" id="btn-unlock">🔓 Release Lockouts</button>
            <button class="btn outline" id="btn-clear">🗑️ Clear Logs</button>
          </div>
        </div>

        <div class="stats-grid">
          <div class="stat-box">
            <div class="stat-label">Security Violations Blocked</div>
            <div class="stat-value" style="color: #38bdf8;">${total}</div>
          </div>
          <div class="stat-box">
            <div class="stat-label">Active IP Lockouts</div>
            <div class="stat-value" style="color: ${lockouts > 0 ? '#ef4444' : '#22c55e'};">${lockouts}</div>
          </div>
          <div class="stat-box">
            <div class="stat-label">Security Boundary</div>
            <div class="stat-value" style="color: #a855f7; font-size: 1rem; margin-top: 0.4rem;">user/pages/ & RAG</div>
          </div>
        </div>

        <div class="table-container">
          ${logs.length === 0 ? `
            <div class="empty-state">No security threat violations or blocked attacks recorded.</div>
          ` : `
            <table>
              <thead>
                <tr>
                  <th>Timestamp</th>
                  <th>IP Hash</th>
                  <th>Attack Category / Triggered Rule</th>
                  <th>Action Taken</th>
                </tr>
              </thead>
              <tbody>
                ${logs.map(log => `
                  <tr>
                    <td style="white-space: nowrap;">${log.timestamp || 'N/A'}</td>
                    <td><code>${log.ip_hash || 'Unknown'}</code></td>
                    <td style="color: #f87171;">${log.reason || 'Security Guardrail Match'}</td>
                    <td><span class="badge warning">Blocked</span></td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          `}
        </div>
      </div>
    `;

    const btnRefresh = this.shadowRoot.querySelector('#btn-refresh');
    const btnUnlock = this.shadowRoot.querySelector('#btn-unlock');
    const btnClear = this.shadowRoot.querySelector('#btn-clear');

    if (btnRefresh) btnRefresh.addEventListener('click', () => this._fetchData());
    if (btnUnlock) btnUnlock.addEventListener('click', () => this._handleAction('release_ip_lockouts'));
    if (btnClear) btnClear.addEventListener('click', () => this._handleAction('clear_security_logs'));
  }
}

try {
  if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
    customElements.define(TAG, ChatbotSecurityLogs);
  }
} catch (e) {
  // Prevent duplicate definition errors
}
