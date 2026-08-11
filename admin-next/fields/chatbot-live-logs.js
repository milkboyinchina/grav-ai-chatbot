/**
 * ChatbotLiveLogsElement
 * Native Grav Admin 2 Web Component for streaming AI Chatbot live error & interaction log feeds.
 * Auto-discovered by Admin 2 convention at user/plugins/ai-chatbot/admin-next/fields/chatbot-live-logs.js
 *
 * @license GPL-3.0-or-later
 */
export default class ChatbotLiveLogsElement extends HTMLElement {
    constructor() {
        super();
        this.pollInterval = null;
    }

    connectedCallback() {
        this.renderShell();
        this.fetchLogs();
        this.pollInterval = setInterval(() => this.fetchLogs(), 5000);
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
                .chatbot-logs-container {
                    background: #090d16;
                    border: 1px solid rgba(255, 255, 255, 0.12);
                    border-radius: 12px;
                    padding: 16px;
                    color: #e2e8f0;
                    font-family: 'Fira Code', 'Monaco', monospace;
                    margin-bottom: 16px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
                }
                .chatbot-logs-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 12px;
                    padding-bottom: 8px;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                }
                .chatbot-logs-title {
                    font-weight: 600;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    color: #38bdf8;
                }
                .chatbot-logs-body {
                    background: #020617;
                    border: 1px solid rgba(255, 255, 255, 0.05);
                    border-radius: 8px;
                    padding: 12px;
                    height: 240px;
                    overflow-y: auto;
                    font-size: 0.75rem;
                    line-height: 1.5;
                    white-space: pre-wrap;
                    color: #94a3b8;
                }
                .chatbot-log-entry-error { color: #f87171; }
                .chatbot-log-entry-info { color: #34d399; }
                .chatbot-log-entry-warn { color: #fbbf24; }
            </style>
            <div class="chatbot-logs-container">
                <div class="chatbot-logs-header">
                    <div class="chatbot-logs-title">
                        <span>📜 AI Chatbot Live Streaming Error & Audit Log</span>
                    </div>
                    <button type="button" id="chatbot-clear-log-btn" style="background:rgba(239,68,68,0.2); color:#f87171; border:1px solid rgba(239,68,68,0.4); border-radius:6px; padding:4px 10px; font-size:0.7rem; cursor:pointer;">
                        Clear Feed
                    </button>
                </div>
                <div class="chatbot-logs-body" id="chatbot-logs-output">Loading live log stream...</div>
            </div>
        `;

        const clearBtn = this.querySelector('#chatbot-clear-log-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                const outputEl = this.querySelector('#chatbot-logs-output');
                if (outputEl) outputEl.textContent = 'Log stream cleared.';
            });
        }
    }

    async fetchLogs() {
        try {
            const res = await fetch('/chatbot-api?action=get_live_logs&t=' + Date.now());
            if (!res.ok) return;
            const data = await res.json();

            if (data && data.success && data.logs) {
                const outputEl = this.querySelector('#chatbot-logs-output');
                if (outputEl) {
                    const logsText = data.logs.trim() || 'No log entries recorded yet.';
                    outputEl.textContent = logsText;
                    outputEl.scrollTop = outputEl.scrollHeight;
                }
            }
        } catch (e) {
            // Silence network glitches during live polling
        }
    }
}

try {
    if (typeof customElements !== 'undefined' && !customElements.get('chatbot-live-logs')) {
        customElements.define('chatbot-live-logs', ChatbotLiveLogsElement);
    }
} catch (e) {
    // Ignore double registration in Admin 2 ES module runner
}

if (typeof window !== 'undefined') {
    window.ChatbotLiveLogsElement = ChatbotLiveLogsElement;
}
