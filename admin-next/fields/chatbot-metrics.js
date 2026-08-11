/**
 * ChatbotMetricsElement
 * Native Grav Admin 2 Web Component for real-time AI Chatbot analytics metrics.
 * Conforms strictly to Grav 2.0 API Developer Guide specification.
 *
 * @license GPL-3.0-or-later
 */
const TAG = (typeof window !== 'undefined' && window.__GRAV_FIELD_TAG) ? window.__GRAV_FIELD_TAG : 'chatbot-metrics';

export default class ChatbotMetricsElement extends HTMLElement {
    constructor() {
        super();
        this.pollInterval = null;
    }

    set field(f) {
        this._field = f;
    }

    set value(v) {
        this._value = v;
    }

    get value() {
        return this._value;
    }

    connectedCallback() {
        this.renderShell();
        this.fetchMetrics();
        this.pollInterval = setInterval(() => this.fetchMetrics(), 10000);
    }

    disconnectedCallback() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    renderShell() {
        this.innerHTML = `
            <style>
                :host {
                    display: block;
                    width: 100%;
                    max-width: 100%;
                    box-sizing: border-box;
                }
                .chatbot-metrics-container {
                    box-sizing: border-box;
                    width: 100%;
                    max-width: 100%;
                    background: rgba(15, 23, 42, 0.85);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    border-radius: 12px;
                    padding: 16px;
                    color: #f8fafc;
                    font-family: system-ui, -apple-system, sans-serif;
                    margin: 8px 0 16px 0;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
                    backdrop-filter: blur(10px);
                    overflow: hidden;
                }
                .chatbot-metrics-grid {
                    box-sizing: border-box;
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 12px;
                    margin-bottom: 8px;
                    width: 100%;
                }
                .chatbot-metric-card {
                    box-sizing: border-box;
                    background: rgba(30, 41, 59, 0.7);
                    border: 1px solid rgba(255, 255, 255, 0.08);
                    border-radius: 8px;
                    padding: 12px 14px;
                    display: flex;
                    flex-direction: column;
                    gap: 4px;
                }
                .chatbot-metric-label {
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #94a3b8;
                    font-weight: 600;
                }
                .chatbot-metric-value {
                    font-size: 1.4rem;
                    font-weight: 700;
                    color: #38bdf8;
                }
                .chatbot-metric-sub {
                    font-size: 0.7rem;
                    color: #64748b;
                }
                .chatbot-metrics-header {
                    box-sizing: border-box;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 12px;
                    padding-bottom: 8px;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .chatbot-metrics-title {
                    font-weight: 600;
                    font-size: 0.95rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    color: #e2e8f0;
                }
                .chatbot-metrics-badge {
                    background: #10b981;
                    color: #064e3b;
                    font-size: 0.65rem;
                    font-weight: 700;
                    padding: 2px 8px;
                    border-radius: 9999px;
                    text-transform: uppercase;
                }
            </style>
            <div class="chatbot-metrics-container">
                <div class="chatbot-metrics-header">
                    <div class="chatbot-metrics-title">
                        <span>📊 Real-Time AI Chatbot Metrics</span>
                        <span class="chatbot-metrics-badge">Grav 2.0 API</span>
                    </div>
                    <div style="font-size:0.75rem; color:#94a3b8;" id="chatbot-metrics-updated">Updating...</div>
                </div>
                <div class="chatbot-metrics-grid">
                    <div class="chatbot-metric-card">
                        <span class="chatbot-metric-label">💬 Total Conversations</span>
                        <span class="chatbot-metric-value" id="cm-total-queries">0</span>
                        <span class="chatbot-metric-sub">Past 30 Days</span>
                    </div>
                    <div class="chatbot-metric-card">
                        <span class="chatbot-metric-label">🔤 Output Tokens</span>
                        <span class="chatbot-metric-value" id="cm-completion-tokens" style="color:#a78bfa;">0</span>
                        <span class="chatbot-metric-sub">Generated Tokens</span>
                    </div>
                    <div class="chatbot-metric-card">
                        <span class="chatbot-metric-label">💰 Estimated API Cost</span>
                        <span class="chatbot-metric-value" id="cm-estimated-cost" style="color:#34d399;">$0.0000</span>
                        <span class="chatbot-metric-sub">Token Expenditure</span>
                    </div>
                    <div class="chatbot-metric-card">
                        <span class="chatbot-metric-label">🎯 Active AI Provider</span>
                        <span class="chatbot-metric-value" id="cm-active-provider" style="font-size:1.1rem; color:#f43f5e;">-</span>
                        <span class="chatbot-metric-sub" id="cm-active-model">Model</span>
                    </div>
                </div>
            </div>
        `;
    }

    async fetchMetrics() {
        try {
            const headers = {};
            if (typeof window !== 'undefined' && window.__GRAV_API_TOKEN) {
                headers['X-API-Token'] = window.__GRAV_API_TOKEN;
            }
            const res = await fetch('/chatbot-api?action=get_metrics&t=' + Date.now(), { headers });
            if (!res.ok) return;
            const data = await res.json();

            if (data && data.success) {
                const stats = data.stats || {};
                this.updateEl('cm-total-queries', (stats.total_queries || 0).toLocaleString());
                this.updateEl('cm-completion-tokens', (stats.completion_tokens || 0).toLocaleString());
                this.updateEl('cm-estimated-cost', '$' + (stats.estimated_cost || 0).toFixed(4));
                this.updateEl('cm-active-provider', (data.provider || 'OmniRoute').toUpperCase());
                this.updateEl('cm-active-model', data.model || 'chatbot');

                const updatedEl = this.querySelector('#chatbot-metrics-updated');
                if (updatedEl) {
                    const now = new Date();
                    updatedEl.textContent = 'Updated ' + now.toLocaleTimeString();
                }
            }
        } catch (e) {
            // Silence network glitches during live polling
        }
    }

    updateEl(id, text) {
        const el = this.querySelector('#' + id);
        if (el) el.textContent = text;
    }
}

try {
    if (typeof customElements !== 'undefined' && !customElements.get(TAG)) {
        customElements.define(TAG, ChatbotMetricsElement);
    }
} catch (e) {
    // Ignore duplicate registration
}
