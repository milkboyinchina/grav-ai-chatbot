(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const config = window.GravChatbotConfig || {};
    const apiEndpoint = config.apiEndpoint || '/chatbot-api';
    const retentionDays = parseInt(config.sessionRetentionDays, 10) || 7;
    const STORAGE_KEY = 'grav_ai_chatbot_session_v1';

    const toggleBtn = document.getElementById('grav-chatbot-toggle');
    const closeBtn = document.getElementById('grav-chatbot-close');
    const windowBox = document.getElementById('grav-chatbot-window');
    const messagesFeed = document.getElementById('grav-chatbot-messages');
    const inputForm = document.getElementById('grav-chatbot-form');
    const inputField = document.getElementById('grav-chatbot-input');
    const toastBox = document.getElementById('grav-chatbot-toast');
    const toastClose = document.getElementById('grav-chatbot-toast-close');
    const iconChat = toggleBtn ? toggleBtn.querySelector('.grav-chatbot-icon-chat') : null;
    const iconClose = toggleBtn ? toggleBtn.querySelector('.grav-chatbot-icon-close') : null;

    let history = [];
    let isOpen = false;

    if (!toggleBtn || !windowBox) return;

    loadSession();

    if (config.notificationEnabled && toastBox) {
      const delayMs = (parseInt(config.notificationDelaySeconds, 10) || 4) * 1000;
      setTimeout(function () {
        if (!isOpen && !sessionStorage.getItem('grav_chatbot_toast_dismissed')) {
          toastBox.style.display = 'flex';
        }
      }, delayMs);

      if (toastClose) {
        toastClose.addEventListener('click', function (e) {
          e.stopPropagation();
          dismissToast();
        });
      }

      toastBox.addEventListener('click', function () {
        dismissToast();
        toggleChat(true);
      });
    }

    function dismissToast() {
      if (toastBox) toastBox.style.display = 'none';
      sessionStorage.setItem('grav_chatbot_toast_dismissed', 'true');
    }

    function toggleChat(show) {
      isOpen = show !== undefined ? show : windowBox.style.display === 'none';
      windowBox.style.display = isOpen ? 'flex' : 'none';

      if (iconChat && iconClose) {
        iconChat.style.display = isOpen ? 'none' : 'block';
        iconClose.style.display = isOpen ? 'block' : 'none';
      }

      if (isOpen) {
        dismissToast();
        if (inputField) inputField.focus();
      }

      saveSession();
    }

    toggleBtn.addEventListener('click', function () {
      toggleChat();
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        toggleChat(false);
      });
    }

    function appendMessage(role, text, source, skipSave) {
      const msgDiv = document.createElement('div');
      msgDiv.className = 'grav-chatbot-msg ' + role;

      const bubbleDiv = document.createElement('div');
      bubbleDiv.className = 'grav-chatbot-bubble';

      if (source === 'faq_match') {
        const badge = document.createElement('span');
        badge.className = 'grav-chatbot-badge faq';
        badge.textContent = '⚡ Instant FAQ Match';
        bubbleDiv.appendChild(badge);
        bubbleDiv.appendChild(document.createElement('br'));
      }

      const textNode = document.createElement('span');
      textNode.innerHTML = escapeAndFormatMarkdown(text);
      bubbleDiv.appendChild(textNode);

      // Add Yes/No confirmation buttons if source is faq_match
      if (source === 'faq_match' && !skipSave) {
        const confirmBox = document.createElement('div');
        confirmBox.className = 'grav-chatbot-confirm-box';
        confirmBox.innerHTML = '<span class="grav-chatbot-confirm-label">Is this answer what you were looking for?</span>' +
          '<div class="grav-chatbot-confirm-buttons">' +
          '<button class="grav-chatbot-confirm-btn yes">👍 Yes</button>' +
          '<button class="grav-chatbot-confirm-btn no">👎 No, ask AI</button>' +
          '</div>';

        const yesBtn = confirmBox.querySelector('.yes');
        const noBtn = confirmBox.querySelector('.no');

        yesBtn.addEventListener('click', function () {
          yesBtn.disabled = true;
          noBtn.disabled = true;
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-label" style="color:#10b981; font-weight:600;">✓ Great! Glad I could help.</span>';
        });

        noBtn.addEventListener('click', function () {
          yesBtn.disabled = true;
          noBtn.disabled = true;
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-label" style="color:#6b7280;">Asking AI model...</span>';
          
          let lastUserQ = '';
          for (let i = history.length - 1; i >= 0; i--) {
            if (history[i].role === 'user') {
              lastUserQ = history[i].content;
              break;
            }
          }
          if (lastUserQ) {
            sendQuery(lastUserQ, 'force_ai');
          }
        });

        bubbleDiv.appendChild(confirmBox);
      }

      msgDiv.appendChild(bubbleDiv);
      messagesFeed.appendChild(msgDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;

      history.push({ role: role, content: text, source: source });

      if (!skipSave) {
        saveSession();
      }
    }

    function showTypingIndicator() {
      const typingDiv = document.createElement('div');
      typingDiv.id = 'grav-chatbot-typing';
      typingDiv.className = 'grav-chatbot-msg assistant';
      typingDiv.innerHTML = '<div class="grav-chatbot-bubble"><em>Assistant is thinking...</em></div>';
      messagesFeed.appendChild(typingDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;
    }

    function removeTypingIndicator() {
      const indicator = document.getElementById('grav-chatbot-typing');
      if (indicator) indicator.remove();
    }

    function sendQuery(questionText, action) {
      if (!questionText && action !== 'summarize_page') return;

      if (action !== 'summarize_page' && action !== 'force_ai') {
        appendMessage('user', questionText);
        if (inputField) inputField.value = '';
      } else if (action === 'summarize_page') {
        appendMessage('user', '📝 Summarize current page');
      }

      showTypingIndicator();

      fetch(apiEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          question: questionText,
          action: action || 'query',
          current_route: config.currentRoute || window.location.pathname,
          history: history.slice(-6)
        })
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          removeTypingIndicator();
          if (data.answer) {
            appendMessage('assistant', data.answer, data.source);
          } else {
            appendMessage('assistant', 'Sorry, I encountered an unexpected error.');
          }
        })
        .catch(function (err) {
          removeTypingIndicator();
          appendMessage('assistant', 'Connection error. Please try again later.');
        });
    }

    if (inputForm) {
      inputForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const val = inputField.value.trim();
        if (val) sendQuery(val, 'query');
      });
    }

    // Quick Action Pills
    document.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('grav-chatbot-pill')) {
        const action = e.target.getAttribute('data-action');
        const prompt = e.target.getAttribute('data-prompt');

        if (action === 'summarize_page') {
          sendQuery('', 'summarize_page');
        } else if (action === 'contact') {
          sendQuery('How can I contact the website owner or engineering team?', 'query');
        } else if (prompt) {
          sendQuery(prompt, 'query');
        }
      }
    });

    function saveSession() {
      if (retentionDays <= 0) {
        localStorage.removeItem(STORAGE_KEY);
        return;
      }

      const expiryMs = Date.now() + (retentionDays * 86400000);
      const sessionData = {
        expiry: expiryMs,
        isOpen: isOpen,
        history: history
      };

      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(sessionData));
      } catch (err) {}
    }

    function loadSession() {
      if (retentionDays <= 0) return;

      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;

        const session = JSON.parse(raw);
        if (session && session.expiry && Date.now() < session.expiry) {
          if (Array.isArray(session.history) && session.history.length > 0) {
            messagesFeed.innerHTML = '';
            history = [];
            session.history.forEach(function (msg) {
              appendMessage(msg.role, msg.content, msg.source, true);
            });
          }
          if (session.isOpen) {
            toggleChat(true);
          }
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
      } catch (err) {}
    }

    function escapeAndFormatMarkdown(str) {
      if (!str) return '';
      let safe = str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
      
      safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
      safe = safe.replace(/\n/g, '<br/>');
      return safe;
    }
  });
})();
