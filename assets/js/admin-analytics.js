(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const data = window.GravChatbotAnalytics || {};

    // Update Summary Cards
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

    // Render Daily Volume Chart
    const dailyChartContainer = document.getElementById('chart-daily-volume');
    if (dailyChartContainer && data.daily_chart) {
      const labels = data.daily_chart.labels || [];
      const values = data.daily_chart.values || [];
      const maxVal = Math.max(1, ...values);

      dailyChartContainer.innerHTML = '';
      if (labels.length === 0) {
        dailyChartContainer.innerHTML = '<p class="text-center text-muted">No interactions logged yet.</p>';
      } else {
        labels.forEach(function (label, idx) {
          const val = values[idx];
          const heightPx = Math.max(15, Math.round((val / maxVal) * 150));

          const group = document.createElement('div');
          group.className = 'grav-bar-group';
          group.innerHTML = '<div class="grav-bar" style="height:' + heightPx + 'px;" title="' + val + ' queries"></div>' +
            '<span class="grav-bar-label">' + label.substring(5) + '</span>';
          dailyChartContainer.appendChild(group);
        });
      }
    }

    // Render Ratio Chart
    const ratioChartContainer = document.getElementById('chart-ratio');
    if (ratioChartContainer && data.ratio_chart) {
      const faqHits = data.ratio_chart.faq_hits || 0;
      const aiHits = data.ratio_chart.ai_hits || 0;
      const total = faqHits + aiHits || 1;
      const faqPct = Math.round((faqHits / total) * 100);
      const aiPct = 100 - faqPct;

      ratioChartContainer.innerHTML =
        '<div style="width:100%; display:flex; flex-direction:column; gap:12px;">' +
        '<div><strong>⚡ FAQ Instant Matches (Free):</strong> ' + faqHits + ' (' + faqPct + '%)</div>' +
        '<div style="background:#e2e8f0; border-radius:6px; height:20px; overflow:hidden; display:flex;">' +
        '<div style="width:' + faqPct + '%; background:#10b981;"></div>' +
        '<div style="width:' + aiPct + '%; background:#3b82f6;"></div>' +
        '</div>' +
        '<div><strong>🤖 AI Model API Calls:</strong> ' + aiHits + ' (' + aiPct + '%)</div>' +
        '</div>';
    }

    // Render Recommendations Table
    const tbody = document.getElementById('recommendations-table-body');
    if (tbody) {
      tbody.innerHTML = '';
      const recs = data.recommendations || [];
      if (recs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No FAQ recommendations available yet. Log more AI queries to see candidates!</td></tr>';
      } else {
        recs.forEach(function (rec) {
          const tr = document.createElement('tr');
          tr.innerHTML = '<td><span class="badge">' + rec.count + 'x asked</span></td>' +
            '<td><strong>' + escapeHtml(rec.sample_question) + '</strong></td>' +
            '<td>' + escapeHtml(rec.suggested_answer).substring(0, 140) + '...</td>';
          tbody.appendChild(tr);
        });
      }
    }

    // Test AI API Connection Button Event Listener
    const testApiBtn = document.getElementById('grav-chatbot-test-api-btn');
    const testApiResult = document.getElementById('grav-chatbot-test-api-result');

    if (testApiBtn) {
      testApiBtn.addEventListener('click', async function (e) {
        e.preventDefault();

        // Query input fields from Grav Admin plugin settings form
        const providerEl = document.querySelector('[name*="[provider]"]');
        const apiKeyEl = document.querySelector('[name*="[api_key]"]');
        const modelEl = document.querySelector('[name*="[model]"]');
        const customEndpointEl = document.querySelector('[name*="[custom_endpoint]"]');

        const provider = providerEl ? providerEl.value : 'gemini';
        const apiKey = apiKeyEl ? apiKeyEl.value : '';
        const model = modelEl ? modelEl.value : 'gemini-1.5-flash';
        const customEndpoint = customEndpointEl ? customEndpointEl.value : '';

        testApiBtn.disabled = true;
        testApiBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testing AI Connection...';

        if (testApiResult) {
          testApiResult.style.display = 'block';
          testApiResult.className = 'notice info';
          testApiResult.innerHTML = '<p><i class="fa fa-circle-o-notch fa-spin"></i> Connecting to AI provider API endpoint...</p>';
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
          testApiBtn.innerHTML = '<i class="fa fa-plug"></i> Test AI Connection';

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
          testApiBtn.innerHTML = '<i class="fa fa-plug"></i> Test AI Connection';

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

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
  });
})();
