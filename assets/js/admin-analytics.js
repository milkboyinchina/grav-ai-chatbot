(function () {
  function initAdminAnalytics() {
    let targetEl = document.getElementById('grav-chatbot-analytics-target');

    if (!targetEl) {
      const allEls = document.querySelectorAll('p, div, span, fieldset, form');
      allEls.forEach(function (el) {
        if (!targetEl && el.textContent && el.textContent.includes('Loading real-time interaction metrics...')) {
          targetEl = el;
        }
      });
    }

    if (targetEl && !targetEl.dataset.analyticsMounted) {
      targetEl.dataset.analyticsMounted = 'true';

      targetEl.innerHTML = `
        <div class="grav-chatbot-analytics-dashboard" style="margin-top: 15px;">
          <!-- Metric Cards -->
          <div class="grav-analytics-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px;">
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; color: #fff;">
              <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Total Queries</div>
              <div id="card-total-queries" style="font-size: 1.8rem; font-weight: 700; color: #38bdf8; margin-top: 4px;">...</div>
            </div>
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; color: #fff;">
              <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">FAQ Matches (Free Hits)</div>
              <div id="card-faq-hits" style="font-size: 1.8rem; font-weight: 700; color: #34d399; margin-top: 4px;">...</div>
            </div>
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; color: #fff;">
              <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">AI API Requests</div>
              <div id="card-ai-hits" style="font-size: 1.8rem; font-weight: 700; color: #818cf8; margin-top: 4px;">...</div>
            </div>
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; color: #fff;">
              <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Est. API Tokens</div>
              <div id="card-total-tokens" style="font-size: 1.8rem; font-weight: 700; color: #fb923c; margin-top: 4px;">...</div>
            </div>
          </div>

          <!-- Actions Bar -->
          <div style="margin-bottom: 25px; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="/chatbot-export?format=csv" target="_blank" class="button btn btn-primary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600;">📥 Export CSV Report</a>
            <a href="/chatbot-export?format=json" target="_blank" class="button btn btn-secondary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600;">📄 Export JSON Data</a>
          </div>

          <!-- Charts Grid -->
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px;">
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 18px;">
              <h4 style="margin-top:0; font-size: 1rem; color: #e2e8f0;">📈 Daily Interaction Volume</h4>
              <div id="chart-daily-volume" style="min-height: 140px; display: flex; align-items: flex-end; gap: 8px; padding-top: 15px;"></div>
            </div>
            <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 18px;">
              <h4 style="margin-top:0; font-size: 1rem; color: #e2e8f0;">📊 Query Source Distribution Ratio</h4>
              <div id="chart-ratio" style="min-height: 140px; display: flex; align-items: center;"></div>
            </div>
          </div>

          <!-- Recommendations Table -->
          <div style="background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 18px;">
            <h4 style="margin-top:0; font-size: 1rem; color: #e2e8f0;">💡 Candidate FAQ Recommendations</h4>
            <p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 12px;">Visitor questions frequently handled by AI API. Adding them to <code>/faq</code> page will answer future queries for free!</p>
            <table style="width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="border-bottom: 1px solid #334155; text-align: left; color: #cbd5e1; font-size: 0.85rem;">
                  <th style="padding: 8px;">Frequency</th>
                  <th style="padding: 8px;">Sample Visitor Question</th>
                  <th style="padding: 8px;">AI Generated Response</th>
                </tr>
              </thead>
              <tbody id="recommendations-table-body">
                <tr>
                  <td colspan="3" style="padding: 12px; text-align: center; color: #94a3b8;">Loading recommendations...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      `;

      fetchAnalyticsData();
    }

    attachTestApiListener();
  }

  async function fetchAnalyticsData() {
    try {
      const res = await fetch('/chatbot-api', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'analytics_report' })
      });
      const resData = await res.json();
      if (resData.success && resData.analytics) {
        renderAnalytics(resData.analytics);
      }
    } catch (e) {
      console.warn('GravChatbot: Could not fetch analytics data', e);
    }
  }

  function renderAnalytics(data) {
    const cardTotal = document.getElementById('card-total-queries');
    const cardFaq = document.getElementById('card-faq-hits');
    const cardAi = document.getElementById('card-ai-hits');
    const cardTokens = document.getElementById('card-total-tokens');

    if (data.summary) {
      if (cardTotal) cardTotal.textContent = data.summary.total_queries || 0;
      if (cardFaq) cardFaq.textContent = data.summary.faq_hits || 0;
      if (cardAi) cardAi.textContent = data.summary.ai_hits || 0;
      if (cardTokens) cardTokens.textContent = (data.summary.total_tokens || 0).toLocaleString();
    }

    // Daily Volume Chart
    const dailyChartContainer = document.getElementById('chart-daily-volume');
    if (dailyChartContainer && data.daily_chart) {
      const labels = data.daily_chart.labels || [];
      const values = data.daily_chart.values || [];
      const maxVal = Math.max(1, ...values);

      dailyChartContainer.innerHTML = '';
      if (labels.length === 0) {
        dailyChartContainer.innerHTML = '<p style="color:#94a3b8; margin:auto;">No interactions logged yet.</p>';
      } else {
        labels.forEach(function (label, idx) {
          const val = values[idx];
          const heightPx = Math.max(15, Math.round((val / maxVal) * 110));

          const group = document.createElement('div');
          group.style.display = 'flex';
          group.style.flexDirection = 'column';
          group.style.alignItems = 'center';
          group.style.flex = '1';

          group.innerHTML = `
            <div style="height:${heightPx}px; width: 100%; max-width: 32px; background: #38bdf8; border-radius: 4px;" title="${val} queries"></div>
            <span style="font-size: 0.75rem; color: #94a3b8; margin-top: 6px;">${label.substring(5)}</span>
          `;
          dailyChartContainer.appendChild(group);
        });
      }
    }

    // Ratio Chart
    const ratioChartContainer = document.getElementById('chart-ratio');
    if (ratioChartContainer && data.ratio_chart) {
      const faqHits = data.ratio_chart.faq_hits || 0;
      const aiHits = data.ratio_chart.ai_hits || 0;
      const total = faqHits + aiHits || 1;
      const faqPct = Math.round((faqHits / total) * 100);
      const aiPct = 100 - faqPct;

      ratioChartContainer.innerHTML = `
        <div style="width:100%; display:flex; flex-direction:column; gap:12px; color: #e2e8f0;">
          <div><strong style="color:#34d399;">⚡ FAQ Instant Matches (Free):</strong> ${faqHits} (${faqPct}%)</div>
          <div style="background:#334155; border-radius:6px; height:18px; overflow:hidden; display:flex;">
            <div style="width:${faqPct}%; background:#34d399;"></div>
            <div style="width:${aiPct}%; background:#818cf8;"></div>
          </div>
          <div><strong style="color:#818cf8;">🤖 AI Model API Calls:</strong> ${aiHits} (${aiPct}%)</div>
        </div>
      `;
    }

    // Recommendations Table
    const tbody = document.getElementById('recommendations-table-body');
    if (tbody) {
      tbody.innerHTML = '';
      const recs = data.recommendations || [];
      if (recs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="padding:12px; text-align:center; color:#94a3b8;">No FAQ recommendations available yet. Log more AI queries to see candidates!</td></tr>';
      } else {
        recs.forEach(function (rec) {
          const tr = document.createElement('tr');
          tr.style.borderBottom = '1px solid #334155';
          tr.style.color = '#cbd5e1';
          tr.innerHTML = `
            <td style="padding:8px;"><span style="background:#3b82f6; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.8rem;">${rec.count}x asked</span></td>
            <td style="padding:8px;"><strong>${escapeHtml(rec.sample_question)}</strong></td>
            <td style="padding:8px; font-size:0.85rem; color:#94a3b8;">${escapeHtml(rec.suggested_answer).substring(0, 140)}...</td>
          `;
          tbody.appendChild(tr);
        });
      }
    }
  }

  function attachTestApiListener() {
    const testApiBtn = document.getElementById('grav-chatbot-test-api-btn');
    const testApiResult = document.getElementById('grav-chatbot-test-api-result');

    if (testApiBtn && !testApiBtn.dataset.bound) {
      testApiBtn.dataset.bound = 'true';
      testApiBtn.addEventListener('click', async function (e) {
        e.preventDefault();

        const providerEl = document.querySelector('[name*="[provider]"]');
        const apiKeyEl = document.querySelector('[name*="[api_key]"]');
        const modelEl = document.querySelector('[name*="[model]"]');
        const customEndpointEl = document.querySelector('[name*="[custom_endpoint]"]');

        const provider = providerEl ? providerEl.value : 'gemini';
        const apiKey = apiKeyEl ? apiKeyEl.value : '';
        const model = modelEl ? modelEl.value : 'gemini-1.5-flash';
        const customEndpoint = customEndpointEl ? customEndpointEl.value : '';

        testApiBtn.disabled = true;
        testApiBtn.innerHTML = '⏳ Testing AI Connection...';

        if (testApiResult) {
          testApiResult.style.display = 'block';
          testApiResult.className = 'notice info';
          testApiResult.style.background = '#1e293b';
          testApiResult.style.color = '#38bdf8';
          testApiResult.style.padding = '10px 14px';
          testApiResult.style.borderRadius = '6px';
          testApiResult.style.border = '1px solid #38bdf8';
          testApiResult.innerHTML = '<p style="margin:0;">Connecting to AI provider API endpoint...</p>';
        }

        try {
          const res = await fetch('/chatbot-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'test_connection',
              provider: provider,
              api_key: apiKey,
              model: model,
              custom_endpoint: customEndpoint
            })
          });

          const resData = await res.json();
          testApiBtn.disabled = false;
          testApiBtn.innerHTML = '🔌 Test AI Connection';

          if (testApiResult) {
            if (resData.success) {
              testApiResult.className = 'notice success';
              testApiResult.style.background = '#d1fae5';
              testApiResult.style.color = '#065f46';
              testApiResult.style.padding = '10px 14px';
              testApiResult.style.borderRadius = '6px';
              testApiResult.style.border = '1px solid #10b981';
              testApiResult.innerHTML = '<p style="margin:0;"><strong>✅ Success:</strong> ' + escapeHtml(resData.message) + '</p>';
            } else {
              testApiResult.className = 'notice error alert';
              testApiResult.style.background = '#fee2e2';
              testApiResult.style.color = '#991b1b';
              testApiResult.style.padding = '10px 14px';
              testApiResult.style.borderRadius = '6px';
              testApiResult.style.border = '1px solid #ef4444';
              testApiResult.innerHTML = '<p style="margin:0;"><strong>❌ Connection Failed:</strong> ' + escapeHtml(resData.message) + '</p>';
            }
          }
        } catch (err) {
          testApiBtn.disabled = false;
          testApiBtn.innerHTML = '🔌 Test AI Connection';

          if (testApiResult) {
            testApiResult.className = 'notice error alert';
            testApiResult.style.background = '#fee2e2';
            testApiResult.style.color = '#991b1b';
            testApiResult.style.padding = '10px 14px';
            testApiResult.style.borderRadius = '6px';
            testApiResult.style.border = '1px solid #ef4444';
            testApiResult.innerHTML = '<p style="margin:0;"><strong>❌ Connection Error:</strong> Could not reach the chatbot API endpoint.</p>';
          }
        }
      });
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminAnalytics);
  } else {
    initAdminAnalytics();
  }

  // Polling check for SPA route navigation changes in Grav Admin 2
  setInterval(initAdminAnalytics, 1000);
})();
