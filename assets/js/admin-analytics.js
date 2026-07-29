(function () {
  'use strict';

  function findTargetElement(cssClass, keywords, elementType) {
    var typeSelector = elementType || '*';

    // 1. Direct CSS class match
    if (cssClass) {
      var directEl = document.querySelector('.' + cssClass + ' ' + typeSelector + ', ' + typeSelector + '.' + cssClass);
      if (directEl) return directEl;
    }

    if (!Array.isArray(keywords)) keywords = [keywords];

    // 2. Check name attribute
    for (var i = 0; i < keywords.length; i++) {
      var kw = keywords[i];
      var el = document.querySelector(typeSelector + '[name*="' + kw + '"]');
      if (el) return el;
    }

    // 3. Check id attribute
    for (var j = 0; j < keywords.length; j++) {
      var kw = keywords[j];
      var el = document.querySelector(typeSelector + '[id*="' + kw + '"]');
      if (el) return el;
    }

    // 4. Check candidate label inside immediate .form-field container
    var candidates = Array.from(document.querySelectorAll(typeSelector));
    for (var k = 0; k < candidates.length; k++) {
      var candidate = candidates[k];
      var fieldBlock = candidate.closest('.form-field, .form-group');
      if (fieldBlock) {
        var labelEl = fieldBlock.querySelector('.form-label, label');
        if (labelEl) {
          var text = labelEl.innerText || labelEl.textContent || '';
          for (var m = 0; m < keywords.length; m++) {
            if (text.toLowerCase().indexOf(keywords[m].toLowerCase()) !== -1) {
              return candidate;
            }
          }
        }
      }
    }

    return null;
  }

  function updateFormFields(data) {
    if (!data) return;

    // 1. Error Log Textarea
    var errorLogTextarea = findTargetElement('grav-chatbot-error-log-field', ['ai_chatbot_error_log_display', 'error_log_display', 'live error log'], 'textarea');
    if (errorLogTextarea && typeof data.error_logs === 'string') {
      errorLogTextarea.value = data.error_logs;
      errorLogTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      errorLogTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 2. Summary Input
    var summaryInput = findTargetElement('grav-chatbot-summary-field', ['analytics_summary_text', 'summary_text', 'summary metrics'], 'input') || findTargetElement('grav-chatbot-summary-field', ['analytics_summary_text', 'summary_text'], 'textarea');
    if (summaryInput && data.summary) {
      var s = data.summary;
      var totalQueries = s.total_queries || 0;
      var faqHits = s.faq_hits || 0;
      var aiHits = s.ai_hits || 0;
      var totalTokens = (s.total_tokens || 0).toLocaleString();
      var totalCost = (s.total_cost_usd || 0).toFixed(4);
      var faqPct = totalQueries > 0 ? Math.round((faqHits / totalQueries) * 100) : 0;

      summaryInput.value = 'Total Queries: ' + totalQueries + ' | FAQ Matches: ' + faqHits + ' (' + faqPct + '% Saved) | AI Calls: ' + aiHits + ' | Total Tokens: ' + totalTokens + ' | Est. Cost: $' + totalCost;
      summaryInput.dispatchEvent(new Event('input', { bubbles: true }));
      summaryInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 3. Chart Textarea
    var chartTextarea = findTargetElement('grav-chatbot-chart-field', ['analytics_chart_display', 'chart_display', 'source distribution chart'], 'textarea');
    if (chartTextarea && data.daily_chart) {
      var labels = data.daily_chart.labels || [];
      var values = data.daily_chart.values || [];
      var maxVal = Math.max(1, Math.max.apply(null, values.length ? values : [1]));

      var lines = ['DAILY INTERACTION VOLUME:'];
      if (labels.length === 0) {
        lines.push('  (No interaction data logged yet)');
      } else {
        var slicedL = labels.length > 25 ? labels.slice(labels.length - 25) : labels;
        var slicedV = values.length > 25 ? values.slice(values.length - 25) : values;

        slicedL.forEach(function (l, idx) {
          var v = slicedV[idx];
          var len = Math.max(1, Math.round((v / maxVal) * 20));
          lines.push('  ' + l + ' : ' + '█'.repeat(len) + ' (' + v + ' queries)');
        });
      }

      if (data.ratio_chart) {
        var f = data.ratio_chart.faq_hits || 0;
        var a = data.ratio_chart.ai_hits || 0;
        var r = data.ratio_chart.rate_limit_hits || 0;
        var tot = f + a + r || 1;
        var fp = Math.round((f / tot) * 100);
        var ap = Math.round((a / tot) * 100);
        var rp = Math.round((r / tot) * 100);

        lines.push('');
        lines.push('QUERY SOURCE DISTRIBUTION RATIO:');
        lines.push('  FAQ Matches (Free) : ' + '█'.repeat(Math.max(0, Math.round((fp / 100) * 20))) + ' ' + f + ' (' + fp + '%)');
        lines.push('  AI Model Calls     : ' + '█'.repeat(Math.max(0, Math.round((ap / 100) * 20))) + ' ' + a + ' (' + ap + '%)');
        lines.push('  Rate Limit Shield  : ' + '█'.repeat(Math.max(0, Math.round((rp / 100) * 20))) + ' ' + r + ' (' + rp + '%)');
      }
      chartTextarea.value = lines.join('\n');
      chartTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      chartTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 4. Recommendations Textarea
    var recsTextarea = findTargetElement('grav-chatbot-recs-field', ['analytics_recommendations_text', 'recommendations_text', 'candidate faq'], 'textarea');
    if (recsTextarea && data.recommendations) {
      var recs = data.recommendations || [];
      if (recs.length === 0) {
        recsTextarea.value = 'No candidate FAQ recommendations at this time. All interactions logged in user/data/ai-chatbot/interactions.json.';
      } else {
        var recLines = recs.map(function (rec) {
          return '• [' + rec.count + 'x asked] Q: ' + rec.sample_question + ' => A: ' + rec.suggested_answer.substring(0, 100) + '...';
        });
        recsTextarea.value = recLines.join('\n\n');
      }
      recsTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      recsTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function fetchLiveDashboardData() {
    fetch('/chatbot-api?t=' + Date.now(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'analytics_report' })
    }).then(function(res) { return res.json(); })
      .then(function(resData) {
        if (resData.success && resData.analytics) {
          updateFormFields(resData.analytics);
        }
      }).catch(function(err) {});
  }

  function attachRegenerateListener() {
    var btn = document.getElementById('grav-chatbot-regenerate-btn');
    var statusEl = document.getElementById('grav-chatbot-regenerate-status');

    if (btn && !btn.dataset.bound) {
      btn.dataset.bound = 'true';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        btn.disabled = true;
        btn.innerHTML = 'Regenerating Metrics...';

        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.background = '#1e293b';
          statusEl.style.color = '#38bdf8';
          statusEl.style.border = '1px solid #38bdf8';
          statusEl.innerHTML = 'Reading interaction logs from <code>user/data/ai-chatbot/interactions.json</code> and error logs from <code>user/data/ai-chatbot/error.log</code>...';
        }

        fetch('/chatbot-api?t=' + Date.now(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'analytics_report' })
        }).then(function(res) { return res.json(); })
          .then(function(resData) {
            btn.disabled = false;
            btn.innerHTML = 'Update & Regenerate Chart Metrics';

            if (resData.success && resData.analytics) {
              updateFormFields(resData.analytics);

              if (statusEl) {
                statusEl.style.background = '#d1fae5';
                statusEl.style.color = '#065f46';
                statusEl.style.border = '1px solid #10b981';
                statusEl.innerHTML = 'Dashboard metrics, charts & live error logs successfully updated with latest live data!';
              }
            } else {
              if (statusEl) {
                statusEl.style.background = '#fee2e2';
                statusEl.style.color = '#991b1b';
                statusEl.style.border = '1px solid #ef4444';
                statusEl.innerHTML = 'Could not retrieve analytics report.';
              }
            }
          }).catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = 'Update & Regenerate Chart Metrics';
            if (statusEl) {
              statusEl.style.background = '#fee2e2';
              statusEl.style.color = '#991b1b';
              statusEl.style.border = '1px solid #ef4444';
              statusEl.innerHTML = 'Connection error fetching analytics report.';
            }
          });
      });
    }
  }

  function attachTestApiListener() {
    var testApiBtn = document.getElementById('grav-chatbot-test-api-btn');
    var testApiResult = document.getElementById('grav-chatbot-test-api-result');

    if (testApiBtn && !testApiBtn.dataset.bound) {
      testApiBtn.dataset.bound = 'true';
      testApiBtn.addEventListener('click', function (e) {
        e.preventDefault();

        var providerEl = findTargetElement(null, ['provider'], 'select') || document.querySelector('[name*="[provider]"]');
        var apiKeyEl = findTargetElement(null, ['api_key'], 'input') || document.querySelector('[name*="[api_key]"]');
        var modelEl = findTargetElement(null, ['model'], 'input') || document.querySelector('[name*="[model]"]');
        var customEndpointEl = findTargetElement(null, ['custom_endpoint'], 'input') || document.querySelector('[name*="[custom_endpoint]"]');

        var provider = providerEl ? providerEl.value : 'gemini';
        var apiKey = apiKeyEl ? apiKeyEl.value : '';
        var model = modelEl ? modelEl.value : 'gemini-2.0-flash';
        var customEndpoint = customEndpointEl ? customEndpointEl.value : '';

        testApiBtn.disabled = true;
        testApiBtn.innerHTML = 'Testing AI Connection...';

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

        fetch('/chatbot-api?t=' + Date.now(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'test_connection',
            provider: provider,
            api_key: apiKey,
            model: model,
            custom_endpoint: customEndpoint
          })
        }).then(function(res) { return res.json(); })
          .then(function(resData) {
            testApiBtn.disabled = false;
            testApiBtn.innerHTML = 'Test AI Connection';

            if (testApiResult) {
              if (resData.success) {
                testApiResult.className = 'notice success';
                testApiResult.style.background = '#d1fae5';
                testApiResult.style.color = '#065f46';
                testApiResult.style.padding = '10px 14px';
                testApiResult.style.borderRadius = '6px';
                testApiResult.style.border = '1px solid #10b981';
                testApiResult.innerHTML = '<p style="margin:0;"><strong>Success:</strong> ' + escapeHtml(resData.message) + '</p>';
              } else {
                testApiResult.className = 'notice error alert';
                testApiResult.style.background = '#fee2e2';
                testApiResult.style.color = '#991b1b';
                testApiResult.style.padding = '10px 14px';
                testApiResult.style.borderRadius = '6px';
                testApiResult.style.border = '1px solid #ef4444';
                testApiResult.innerHTML = '<p style="margin:0;"><strong>Connection Failed:</strong> ' + escapeHtml(resData.message) + '</p>';
              }
            }
          }).catch(function(err) {
            testApiBtn.disabled = false;
            testApiBtn.innerHTML = 'Test AI Connection';

            if (testApiResult) {
              testApiResult.className = 'notice error alert';
              testApiResult.style.background = '#fee2e2';
              testApiResult.style.color = '#991b1b';
              testApiResult.style.padding = '10px 14px';
              testApiResult.style.borderRadius = '6px';
              testApiResult.style.border = '1px solid #ef4444';
              testApiResult.innerHTML = '<p style="margin:0;"><strong>Connection Error:</strong> Could not reach the chatbot API endpoint.</p>';
            }
          });
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
