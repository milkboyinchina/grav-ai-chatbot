(function () {
    function g(i) {
        return document.querySelector('[name="data[' + i + ']"]') ||
            document.getElementById('data[' + i + ']') ||
            document.getElementsByName('data[' + i + ']')[0];
    }

    document.addEventListener('DOMContentLoaded', function () {
        var fetchBtn = document.getElementById('grav-chatbot-fetch-models-btn');
        var testBtn = document.getElementById('grav-chatbot-test-model-btn');
        var statusEl = document.getElementById('grav-chatbot-model-status');
        var selWrapper = document.getElementById('grav-chatbot-model-select-wrapper');
        var selEl = document.getElementById('grav-chatbot-model-select');

        if (fetchBtn) {
            fetchBtn.addEventListener('click', function () {
                var p = (g('provider') || {}).value || 'omniroute';
                var k = (g('api_key') || {}).value || '';
                var e = (g('custom_endpoint') || {}).value || '';

                fetchBtn.disabled = true;
                if (statusEl) {
                    statusEl.style.display = 'block';
                    statusEl.style.background = '#f3f4f6';
                    statusEl.style.color = '#1f2937';
                    statusEl.innerHTML = '⏳ Querying active model list from provider API...';
                }

                fetch('/chatbot-api?t=' + Date.now(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fetch_models',
                        provider: p,
                        api_key: k,
                        custom_endpoint: e
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        fetchBtn.disabled = false;
                        if (d.success && d.models && d.models.length) {
                            if (statusEl) {
                                statusEl.style.background = '#d1fae5';
                                statusEl.style.color = '#065f46';
                                statusEl.innerHTML = '✅ Successfully retrieved ' + d.models.length + ' active models!';
                            }
                            if (selWrapper && selEl) {
                                selWrapper.style.display = 'block';
                                selEl.innerHTML = d.models.map(function (m) {
                                    return '<option value="' + m + '">' + m + '</option>';
                                }).join('');
                                selEl.onchange = function () {
                                    var mEl = g('model');
                                    if (mEl) {
                                        mEl.value = selEl.value;
                                    }
                                };
                            }
                        } else {
                            if (statusEl) {
                                statusEl.style.background = '#fee2e2';
                                statusEl.style.color = '#991b1b';
                                statusEl.innerHTML = '❌ ' + (d.message || 'Failed to retrieve models');
                            }
                        }
                    })
                    .catch(function (err) {
                        fetchBtn.disabled = false;
                        if (statusEl) {
                            statusEl.style.background = '#fee2e2';
                            statusEl.style.color = '#991b1b';
                            statusEl.innerHTML = '❌ Error: ' + err.message;
                        }
                    });
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var p = (g('provider') || {}).value || 'omniroute';
                var k = (g('api_key') || {}).value || '';
                var m = (g('model') || {}).value || 'gemini-3.1-flash-lite';
                var e = (g('custom_endpoint') || {}).value || '';
                var fb = (g('fallback_endpoint') || {}).value || '';

                testBtn.disabled = true;
                if (statusEl) {
                    statusEl.style.display = 'block';
                    statusEl.style.background = '#f3f4f6';
                    statusEl.style.color = '#1f2937';
                    statusEl.innerHTML = "⏳ Sending live health check ping to model '" + m + "'...";
                }

                fetch('/chatbot-api?t=' + Date.now(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'test_model_health',
                        provider: p,
                        api_key: k,
                        model: m,
                        custom_endpoint: e,
                        fallback_endpoint: fb
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        testBtn.disabled = false;
                        if (statusEl) {
                            if (d.success) {
                                statusEl.style.background = '#d1fae5';
                                statusEl.style.color = '#065f46';
                            } else {
                                statusEl.style.background = '#fee2e2';
                                statusEl.style.color = '#991b1b';
                            }
                            statusEl.innerHTML = d.message;
                        }
                    })
                    .catch(function (err) {
                        testBtn.disabled = false;
                        if (statusEl) {
                            statusEl.style.background = '#fee2e2';
                            statusEl.style.color = '#991b1b';
                            statusEl.innerHTML = '❌ Connection Error: ' + err.message;
                        }
                    });
            });
        }
    });
})();
