(function () {
  'use strict';

  function findTargetElement(keywords, elementType) {
    if (!Array.isArray(keywords)) keywords = [keywords];
    const typeSelector = elementType || '*';

    // 1. Check name attribute
    for (let kw of keywords) {
      let el = document.querySelector(`${typeSelector}[name*="${kw}"]`);
      if (el) return el;
    }

    // 2. Check id attribute
    for (let kw of keywords) {
      let el = document.querySelector(`${typeSelector}[id*="${kw}"]`);
      if (el) return el;
    }

    // 3. Search all elements of type matching label parent text
    const candidates = Array.from(document.querySelectorAll(typeSelector));
    for (let candidate of candidates) {
      const parentBlock = candidate.closest('.form-field, .form-group, fieldset, tr, td, div');
      if (parentBlock) {
        const text = parentBlock.innerText || parentBlock.textContent || '';
        for (let kw of keywords) {
          if (text.toLowerCase().includes(kw.toLowerCase())) {
            return candidate;
          }
        }
      }
    }

    return null;
  }

  function updateFormFields(data) {
    if (!data) return;

    // 1. Error Log Textarea
    const errorLogTextarea = findTargetElement(['ai_chatbot_error_log_display', 'error_log_display', 'live error log'], 'textarea');
    if (errorLogTextarea && typeof data.error_logs === 'string') {
      errorLogTextarea.value = data.error_logs;
      // Trigger input/change event for reactive SPA frameworks
      errorLogTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      errorLogTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 2. Summary Input
    const summaryInput = findTargetElement(['analytics_summary_text', 'summary_text', 'summary metrics'], 'input') || findTargetElement(['analytics_summary_text', 'summary_text'], 'textarea');
    if (summaryInput && data.summary) {
      const s = data.summary;
      const totalQueries = s.total_queries || 0;
      const faqHits = s.faq_hits || 0;
      const aiHits = s.ai_hits || 0;
      const totalTokens = (s.total_tokens || 0).toLocaleString();
      const totalCost = (s.total_cost_usd || 0).toFixed(4);
      const faqPct = totalQueries > 0 ? Math.round((faqHits / totalQueries) * 100) : 0;

      summaryInput.value = `Total Queries: ${totalQueries} | FAQ Matches: ${faqHits} (${faqPct}% Saved) | AI Calls: ${aiHits} | Total Tokens: ${totalTokens} | Est. Cost: $${totalCost}`;
      summaryInput.dispatchEvent(new Event('input', { bubbles: true }));
      summaryInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 3. Chart Textarea
    const chartTextarea = findTargetElement(['analytics_chart_display', 'chart_display', 'source distribution chart'], 'textarea');
    if (chartTextarea && data.daily_chart) {
      const labels = data.daily_chart.labels || [];
      const values = data.daily_chart.values || [];
      const maxVal = Math.max(1, ...values);

      let lines = ['📈 DAILY INTERACTION VOLUME:'];
      if (labels.length === 0) {
        lines.push('  (No interaction data logged yet)');
      } else {
        const slicedL = labels.length > 25 ? labels.slice(labels.length - 25) : labels;
        const slicedV = values.length > 25 ? values.slice(values.length - 25) : values;

        slicedL.forEach(function (l, idx) {
          const v = slicedV[idx];
          const len = Math.max(1, Math.round((v / maxVal) * 20));
          lines.push(`  ${l} : ${'█'.repeat(len)} (${v} queries)`);
        });
      }

      if (data.ratio_chart) {
        const f = data.ratio_chart.faq_hits || 0;
        const a = data.ratio_chart.ai_hits || 0;
        const r = data.ratio_chart.rate_limit_hits || 0;
        const tot = f + a + r || 1;
        const fp = Math.round((f / tot) * 100);
        const ap = Math.round((a / tot) * 100);
        const rp = Math.round((r / tot) * 100);

        lines.push('');
        lines.push('📊 QUERY SOURCE DISTRIBUTION RATIO:');
        lines.push(`  ⚡ FAQ Matches (Free) : ${'█'.repeat(Math.max(0, Math.round((fp / 100) * 20)))} ${f} (${fp}%)`);
        lines.push(`  🤖 AI Model Calls     : ${'█'.repeat(Math.max(0, Math.round((ap / 100) * 20)))} ${a} (${ap}%)`);
        lines.push(`  🛡️ Rate Limit Shield  : ${'█'.repeat(Math.max(0, Math.round((rp / 100) * 20)))} ${r} (${rp}%)`);
      }
      chartTextarea.value = lines.join('\n');
      chartTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      chartTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 4. Recommendations Textarea
    const recsTextarea = findTargetElement(['analytics_recommendations_text', 'recommendations_text', 'candidate faq'], 'textarea');
    if (recsTextarea && data.recommendations) {
      const recs = data.recommendations || [];
      if (recs.length === 0) {
        recsTextarea.value = 'No candidate FAQ recommendations at this time. All interactions logged in user/data/ai-chatbot/interactions.json.';
      } else {
        const recLines = recs.map(function (rec) {
          return `• [${rec.count}x asked] Q: ${rec.sample_question} => A: ${rec.suggested_answer.substring(0, 100)}...`;
        });
        recsTextarea.value = recLines.join('\n\n');
      }
      recsTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      recsTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  async function fetchLiveDashboardData() {
    try {
      const res = await fetch('/chatbot-api?t=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'analytics_report' })
      });
      const resData = await res.json();
      if (resData.success && resData.analytics) {
        updateFormFields(resData.analytics);
      }
    } catch (err) {
      // Ignore silently
    }
  }

  function attachRegenerateListener() {
    const btn = document.getElementById('grav-chatbot-regenerate-btn');
    const statusEl = document.getElementById('grav-chatbot-regenerate-status');

    if (btn && !btn.dataset.bound) {
      btn.dataset.bound = 'true';
      btn.addEventListener('click', async function (e) {
        e.preventDefault();
        btn.disabled = true;
        btn.innerHTML = '⏳ Regenerating Metrics...';

        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.background = '#1e293b';
          statusEl.style.color = '#38bdf8';
          statusEl.style.border = '1px solid #38bdf8';
          statusEl.innerHTML = 'Reading interaction logs from <code>user/data/ai-chatbot/interactions.json</code> and error logs from <code>user/data/ai-chatbot/error.log</code>...';
        }

        try {
          const res = await fetch('/chatbot-api?t=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'analytics_report' })
          });
          const resData = await res.json();
          btn.disabled = false;
          btn.innerHTML = '🔄 Update & Regenerate Chart Metrics';

          if (resData.success && resData.analytics) {
            const data = resData.analytics;
            updateFormFields(data);

            if (statusEl) {
              statusEl.style.background = '#d1fae5';
              statusEl.style.color = '#065f46';
              statusEl.style.border = '1px solid #10b981';
              statusEl.innerHTML = '✅ Dashboard metrics, charts & live error logs successfully updated with latest live data!';
            }
          } else {
            if (statusEl) {
              statusEl.style.background = '#fee2e2';
              statusEl.style.color = '#991b1b';
              statusEl.style.border = '1px solid #ef4444';
              statusEl.innerHTML = '❌ Could not retrieve analytics report.';
            }
          }
        } catch (err) {
          btn.disabled = false;
          btn.innerHTML = '🔄 Update & Regenerate Chart Metrics';
          if (statusEl) {
            statusEl.style.background = '#fee2e2';
            statusEl.style.color = '#991b1b';
            statusEl.style.border = '1px solid #ef4444';
            statusEl.innerHTML = '❌ Connection error fetching analytics report.';
          }
        }
      });
    }
  }

  function attachTestApiListener() {
    const testApiBtn = document.getElementById('grav-chatbot-test-api-btn');
    const testApiResult = document.getElementById('grav-chatbot-test-api-result');

    if (testApiBtn && !testApiBtn.dataset.bound) {
      testApiBtn.dataset.bound = 'true';
      testApiBtn.addEventListener('click', async function (e) {
        e.preventDefault();

        const providerEl = findTargetElement(['provider'], 'select') || document.querySelector('[name*="[provider]"]');
        const apiKeyEl = findTargetElement(['api_key'], 'input') || document.querySelector('[name*="[api_key]"]');
        const modelEl = findTargetElement(['model'], 'input') || document.querySelector('[name*="[model]"]');
        const customEndpointEl = findTargetElement(['custom_endpoint'], 'input') || document.querySelector('[name*="[custom_endpoint]"]');

        const provider = providerEl ? providerEl.value : 'gemini';
        const apiKey = apiKeyEl ? apiKeyEl.value : '';
        const model = modelEl ? modelEl.value : 'gemini-2.0-flash';
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
          const res = await fetch('/chatbot-api?t=' + Date.now(), {
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

  function init() {
    attachRegenerateListener();
    attachTestApiListener();
    fetchLiveDashboardData();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Automatic Polling Loop Every 2 Seconds
  setInterval(function () {
    attachRegenerateListener();
    attachTestApiListener();
    fetchLiveDashboardData();
  }, 2000);
})();
