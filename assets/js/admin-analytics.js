(function () {
  function initAdminAnalytics() {
    let targetEl = document.getElementById('grav-chatbot-analytics-target');

    if (!targetEl) {
      const allEls = document.querySelectorAll('p, div, span, fieldset, form');
      allEls.forEach(function (el) {
        if (!targetEl && el.textContent && (el.textContent.includes('Loading real-time interaction metrics...') || el.textContent.includes('Live Analytics & Performance Metrics'))) {
          targetEl = el;
        }
      });
    }

    if (targetEl && !targetEl.dataset.analyticsMounted) {
      targetEl.dataset.analyticsMounted = 'true';

      targetEl.innerHTML = `
        <div class="grav-chatbot-analytics-dashboard" style="margin-top: 15px;">
          <!-- Filter & Action Controls Bar -->
          <div class="grav-analytics-controls" style="display: flex; gap: 15px; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; background: #1e293b; padding: 15px; border-radius: 10px; border: 1px solid #334155;">
            <!-- Date Range Filter Dropdown & Refresh -->
            <div style="display: flex; align-items: center; gap: 10px;">
              <label for="grav-analytics-range-select" style="color: #cbd5e1; font-weight: 600; margin: 0; font-size: 0.9rem;">📅 Date Range:</label>
              <select id="grav-analytics-range-select" class="form-select" style="background: #0f172a; color: #fff; border: 1px solid #475569; padding: 6px 12px; border-radius: 6px; font-size: 0.9rem; cursor: pointer;">
                <option value="7d">Last 7 Days</option>
                <option value="1m">Last 1 Month (30 Days)</option>
                <option value="3m">Last 3 Months (90 Days)</option>
                <option value="6m">Last 6 Months (180 Days)</option>
                <option value="12m">Last 12 Months (1 Year)</option>
                <option value="all" selected>All Time</option>
              </select>
              <button type="button" id="grav-analytics-refresh-btn" class="button btn btn-outline-primary" style="padding: 6px 14px; border-radius: 6px; font-weight: 600; cursor: pointer;">🔄 Refresh</button>
            </div>

            <!-- Download Export Links -->
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
              <a id="btn-export-csv" href="/chatbot-export?format=csv&range=all" target="_blank" class="button btn btn-primary" style="padding: 6px 14px; border-radius: 6px; text-decoration: none; font-weight: 600; background: #3b82f6; color: #fff;">📥 Download CSV</a>
              <a id="btn-export-json" href="/chatbot-export?format=json&range=all" target="_blank" class="button btn btn-secondary" style="padding: 6px 14px; border-radius: 6px; text-decoration: none; font-weight: 600; background: #64748b; color: #fff;">📄 Download JSON Data</a>
              <a id="btn-export-raw" href="/chatbot-export?format=raw_interactions&range=all" target="_blank" class="button btn btn-info" style="padding: 6px 14px; border-radius: 6px; text-decoration: none; font-weight: 600; background: #0284c7; color: #fff;">💾 Raw Interactions JSON</a>
            </div>
          </div>

          <!-- Summary Metric Cards -->
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

      // Bind Date Range Dropdown Change Listener
      const rangeSelect = document.getElementById('grav-analytics-range-select');
      if (rangeSelect) {
        rangeSelect.addEventListener('change', function () {
          const selectedRange = rangeSelect.value || 'all';
          updateDownloadLinks(selectedRange);
          fetchAnalyticsData(selectedRange);
        });
      }

      // Bind Refresh Button Click Listener
      const refreshBtn = document.getElementById('grav-analytics-refresh-btn');
      if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
          const selectedRange = rangeSelect ? rangeSelect.value : 'all';
          refreshBtn.innerHTML = '⏳ Loading...';
          refreshBtn.disabled = true;
          fetchAnalyticsData(selectedRange).finally(() => {
            refreshBtn.innerHTML = '🔄 Refresh';
            refreshBtn.disabled = false;
          });
        });
      }

      fetchAnalyticsData('all');
    }

    attachTestApiListener();
  }

  function updateDownloadLinks(range) {
    const btnCsv = document.getElementById('btn-export-csv');
    const btnJson = document.getElementById('btn-export-json');
    const btnRaw = document.getElementById('btn-export-raw');

    if (btnCsv) btnCsv.href = '/chatbot-export?format=csv&range=' + encodeURIComponent(range);
    if (btnJson) btnJson.href = '/chatbot-export?format=json&range=' + encodeURIComponent(range);
    if (btnRaw) btnRaw.href = '/chatbot-export?format=raw_interactions&range=' + encodeURIComponent(range);
  }

  async function fetchAnalyticsData(range) {
    const selectedRange = range || 'all';
    try {
      const res = await fetch('/chatbot-api', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'analytics_report', range: selectedRange })
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
        dailyChartContainer.innerHTML = '<p style="color:#94a3b8; margin:auto;">No interactions logged for selected period.</p>';
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
            <div style="height:${heightPx}px; width: 100%; max-width: 32px; background: #38bdf8; border-radius: 4px;" title="${val} queries on ${label}"></div>
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
        tbody.innerHTML = '<tr><td colspan="3" style="padding:12px; text-align:center; color:#94a3b8;">No FAQ recommendations available for selected period.</td></tr>';
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
