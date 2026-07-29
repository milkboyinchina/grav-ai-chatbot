(function () {
  let isInitialized = false;

  function initChatbot() {
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

    if (!toggleBtn || !windowBox) {
      // Retry initialization if widget elements are rendered dynamically
      if (!isInitialized) {
        setTimeout(initChatbot, 150);
      }
      return;
    }

    isInitialized = true;

    let history = [];
    let isOpen = false;

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
      isOpen = show !== undefined ? show : windowBox.style.display === 'none' || windowBox.style.display === '';
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

    // Attach Click Event Directly to Toggle Button
    toggleBtn.addEventListener('click', function (e) {
      e.preventDefault();
      toggleChat();
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        toggleChat(false);
      });
    }

    // Global Document Click Delegation Fallback for Chat Launcher
    document.addEventListener('click', function (e) {
      const target = e.target.closest('#grav-chatbot-toggle, .grav-chatbot-launcher');
      if (target && !e.defaultPrevented) {
        toggleChat();
      }
    });

    // Quick Reply Pill Click Handler
    document.addEventListener('click', function (e) {
      const pill = e.target.closest('.grav-chatbot-pill');
      if (pill && !e.defaultPrevented) {
        e.preventDefault();
        const action = pill.getAttribute('data-action');
        const prompt = pill.getAttribute('data-prompt');

        if (action === 'summarize_page') {
          sendQuestion(pill.textContent.trim(), 'summarize_page');
        } else if (action === 'contact') {
          sendQuestion('How can I contact support or the team?', 'query');
        } else if (action) {
          sendQuestion(pill.textContent.trim(), action);
        } else if (prompt) {
          sendQuestion(prompt, 'query');
        } else {
          sendQuestion(pill.textContent.trim(), 'query');
        }
      }
    });

    function appendMessage(role, text, source, skipSave) {
      if (!messagesFeed) return;
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

        const promptText = document.createElement('div');
        promptText.className = 'grav-chatbot-confirm-prompt';
        promptText.textContent = 'Is this what you were looking for?';

        const btnYes = document.createElement('button');
        btnYes.type = 'button';
        btnYes.className = 'grav-chatbot-btn-confirm yes';
        btnYes.innerHTML = '👍 Yes';
        btnYes.addEventListener('click', function () {
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-thankyou">Great! Glad we could help. 😊</span>';
        });

        const btnNo = document.createElement('button');
        btnNo.type = 'button';
        btnNo.className = 'grav-chatbot-btn-confirm no';
        btnNo.innerHTML = '👎 No, ask AI';
        btnNo.addEventListener('click', function () {
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-escalating">Connecting to AI Assistant...</span>';
          sendQuestion(text, 'force_ai');
        });

        confirmBox.appendChild(promptText);
        confirmBox.appendChild(btnYes);
        confirmBox.appendChild(btnNo);
        bubbleDiv.appendChild(confirmBox);
      }

      msgDiv.appendChild(bubbleDiv);
      messagesFeed.appendChild(msgDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;

      if (!skipSave) {
        history.push({ role, text, source, timestamp: Date.now() });
        saveSession();
      }
    }

    function showTypingIndicator() {
      if (!messagesFeed) return null;
      const msgDiv = document.createElement('div');
      msgDiv.className = 'grav-chatbot-msg assistant typing-msg';
      msgDiv.innerHTML = `
        <div class="grav-chatbot-bubble">
          <div class="grav-chatbot-typing">
            <span></span><span></span><span></span>
          </div>
        </div>
      `;
      messagesFeed.appendChild(msgDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;
      return msgDiv;
    }

    function removeTypingIndicator(element) {
      if (element && element.parentNode) {
        element.parentNode.removeChild(element);
      }
    }

    function saveSession() {
      try {
        const payload = {
          isOpen,
          history,
          expiry: Date.now() + (retentionDays * 86400 * 1000)
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      } catch (e) {
        console.warn('GravChatbot: localStorage error', e);
      }
    }

    function loadSession() {
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const data = JSON.parse(raw);

        if (data.expiry && Date.now() > data.expiry) {
          localStorage.removeItem(STORAGE_KEY);
          return;
        }

        if (data.isOpen) {
          toggleChat(true);
        }

        if (Array.isArray(data.history)) {
          history = data.history;
          if (messagesFeed) messagesFeed.innerHTML = '';
          history.forEach(item => {
            appendMessage(item.role, item.text, item.source, true);
          });
        }
      } catch (e) {
        localStorage.removeItem(STORAGE_KEY);
      }
    }

    async function sendQuestion(customQuestion, action) {
      const q = customQuestion || (inputField ? inputField.value.trim() : '');
      if (!q) return;

      if (!customQuestion && inputField) {
        inputField.value = '';
      }

      if (action !== 'force_ai') {
        appendMessage('user', q);
      }

      const typingEl = showTypingIndicator();

      try {
        const res = await fetch(apiEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            question: q,
            action: action || 'query',
            history: history.slice(-6),
            current_route: config.currentRoute || '/'
          })
        });

        const data = await res.json();
        removeTypingIndicator(typingEl);

        if (data.answer) {
          appendMessage('assistant', data.answer, data.source);
        } else {
          appendMessage('assistant', 'Sorry, I could not process your request at this time.');
        }
      } catch (err) {
        removeTypingIndicator(typingEl);
        appendMessage('assistant', 'An unexpected connection error occurred. Please try again later.');
      }
    }

    if (inputForm) {
      inputForm.addEventListener('submit', function (e) {
        e.preventDefault();
        sendQuestion();
      });
    }

    function escapeAndFormatMarkdown(str) {
      if (!str) return '';
      let escaped = str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

      // Markdown links: [title](url)
      escaped = escaped.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="grav-chatbot-link" target="_blank" rel="noopener">$1</a>');

      // Bold text: **text**
      escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

      // Headers: ### Header
      escaped = escaped.replace(/^### (.*$)/gim, '<h4 class="grav-chatbot-h4">$1</h4>');

      // Newlines to <br>
      return escaped.replace(/\n/g, '<br>');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initChatbot);
  } else {
    initChatbot();
  }
})();
