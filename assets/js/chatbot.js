(function () {
  'use strict';

  function initGravChatbot() {
    const config = window.GravChatbotConfig || {};
    const apiEndpoint = config.apiEndpoint || '/chatbot-api';
    const position = config.position || 'bottom-right';
    const welcomeMessage = config.welcomeMessage || 'Hello! How can I help you with this website today?';
    const customErrorMessage = config.customErrorMessage || 'An unexpected connection error occurred. Please try again later.';
    const accentColor = config.accentColor || '#3b82f6';
    const themePreset = config.themePreset || 'glass_blue';
    const retentionDays = config.sessionRetentionDays || 7;
    const notificationEnabled = config.notificationEnabled !== false;
    const notificationText = config.notificationText || 'Hi there! Need help finding anything on our website?';
    const notificationDelaySeconds = config.notificationDelaySeconds || 4;
    const quickRepliesEnabled = config.quickRepliesEnabled !== false;
    const quickReplies = config.quickReplies || [];

    const STORAGE_KEY = 'grav_ai_chatbot_session_v1';
    let isOpen = false;
    let history = [];
    let lastUserQuestion = '';

    const widgetRoot = document.getElementById('grav-ai-chatbot-root');
    if (!widgetRoot) return;

    // Apply Position & Theme (preserve grav-chatbot-container)
    widgetRoot.className = 'grav-chatbot-container ' + position + ' theme-' + themePreset;
    if (accentColor && themePreset === 'custom') {
      widgetRoot.style.setProperty('--grav-chatbot-accent', accentColor);
    }

    const toggleBtn = widgetRoot.querySelector('#grav-chatbot-toggle');
    const windowEl = widgetRoot.querySelector('#grav-chatbot-window');
    const closeBtn = widgetRoot.querySelector('#grav-chatbot-close');
    const messagesFeed = widgetRoot.querySelector('#grav-chatbot-messages');
    const inputForm = widgetRoot.querySelector('#grav-chatbot-form');
    const inputField = widgetRoot.querySelector('#grav-chatbot-input');
    const toastEl = widgetRoot.querySelector('#grav-chatbot-toast');
    const toastText = widgetRoot.querySelector('#grav-chatbot-toast-text');
    const toastClose = widgetRoot.querySelector('#grav-chatbot-toast-close');
    const quickRepliesContainer = widgetRoot.querySelector('.grav-chatbot-quick-actions');

    // Render Quick Replies
    if (quickRepliesContainer && quickRepliesEnabled && Array.isArray(quickReplies) && quickReplies.length > 0) {
      quickRepliesContainer.innerHTML = '';
      quickReplies.forEach(qr => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'grav-chatbot-pill';
        btn.textContent = qr.label || qr.action_or_prompt;
        btn.addEventListener('click', function () {
          if (qr.type === 'action') {
            sendQuestion(qr.label || qr.action_or_prompt, qr.action_or_prompt);
          } else {
            sendQuestion(qr.action_or_prompt || qr.label, 'query');
          }
        });
        quickRepliesContainer.appendChild(btn);
      });
    }

    // Toggle Chat Window
    function toggleChat(forceState) {
      isOpen = typeof forceState === 'boolean' ? forceState : !isOpen;
      if (isOpen) {
        if (windowEl) {
          windowEl.style.display = 'flex';
          windowEl.classList.add('active');
        }
        if (toggleBtn) toggleBtn.classList.add('active');
        if (toastEl) {
          toastEl.style.display = 'none';
          toastEl.classList.remove('active');
        }
        if (messagesFeed && messagesFeed.children.length === 0) {
          appendMessage('assistant', welcomeMessage, null, true);
        }
        if (inputField) inputField.focus();
      } else {
        if (windowEl) {
          windowEl.style.display = 'none';
          windowEl.classList.remove('active');
        }
        if (toggleBtn) toggleBtn.classList.remove('active');
      }
      saveSession();
    }

    if (toggleBtn) toggleBtn.addEventListener('click', () => toggleChat());
    if (closeBtn) closeBtn.addEventListener('click', () => toggleChat(false));

    // Proactive Toast Notification
    if (notificationEnabled && toastEl && toastText) {
      toastText.textContent = notificationText;
      setTimeout(() => {
        if (!isOpen && !localStorage.getItem('grav_chatbot_toast_dismissed')) {
          toastEl.style.display = 'flex';
          toastEl.classList.add('active');
        }
      }, notificationDelaySeconds * 1000);

      if (toastClose) {
        toastClose.addEventListener('click', (e) => {
          e.stopPropagation();
          toastEl.style.display = 'none';
          toastEl.classList.remove('active');
          localStorage.setItem('grav_chatbot_toast_dismissed', 'true');
        });
      }

      toastEl.addEventListener('click', () => {
        toastEl.style.display = 'none';
        toastEl.classList.remove('active');
        toggleChat(true);
      });
    }

    function appendMessage(role, text, source, skipSave) {
      if (!messagesFeed) return;
      const msgDiv = document.createElement('div');
      msgDiv.className = 'grav-chatbot-msg ' + role;

      const bubbleDiv = document.createElement('div');
      bubbleDiv.className = 'grav-chatbot-bubble';

      if (source === 'faq_match') {
        const badge = document.createElement('span');
        badge.className = 'grav-chatbot-badge faq';
        badge.textContent = 'Instant FAQ Match';
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
        btnYes.innerHTML = 'Yes';
        btnYes.addEventListener('click', function () {
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-thankyou">Great! Glad we could help.</span>';
        });

        const btnNo = document.createElement('button');
        btnNo.type = 'button';
        btnNo.className = 'grav-chatbot-btn-confirm no';
        btnNo.innerHTML = 'No (Ask AI)';
        btnNo.addEventListener('click', function () {
          confirmBox.innerHTML = '<span class="grav-chatbot-confirm-thankyou">Routing to AI assistant...</span>';
          sendQuestion(lastUserQuestion || 'Help with my query', 'force_ai');
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
        history.push({ role, text, source });
        saveSession();
      }
    }

    function showTypingIndicator() {
      const typingDiv = document.createElement('div');
      typingDiv.className = 'grav-chatbot-msg assistant typing-msg';
      typingDiv.innerHTML = '<div class="grav-chatbot-bubble"><span class="grav-chatbot-typing-dots"><span></span><span></span><span></span></span></div>';
      messagesFeed.appendChild(typingDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;
      return typingDiv;
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

      const maxInputTokens = config.maxInputTokens || 500;
      const maxInputChars = maxInputTokens * 4;

      if (q.length > maxInputChars && action !== 'summarize_page') {
        if (!customQuestion && inputField) {
          inputField.value = q; // Preserve user's text for editing
        }
        appendMessage('assistant', `⚠️ **Message Too Long**: Your question (${q.length} characters) exceeds the maximum allowed input limit of ${maxInputTokens} tokens (~${maxInputChars} characters). Please shorten your message and try again.`, null, true);
        return;
      }

      if (!customQuestion && inputField) {
        inputField.value = '';
      }

      if (action !== 'force_ai') {
        lastUserQuestion = q;
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

        if (data.success && data.answer) {
          appendMessage('assistant', data.answer, data.source);
        } else {
          appendMessage('assistant', data.answer || customErrorMessage);
        }
      } catch (err) {
        removeTypingIndicator(typingEl);
        appendMessage('assistant', customErrorMessage);
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

      // Basic Markdown Formatting
      escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
      escaped = escaped.replace(/\*(.*?)\*/g, '<em>$1</em>');
      escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');
      escaped = escaped.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
      escaped = escaped.replace(/\n/g, '<br>');

      return escaped;
    }

    loadSession();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGravChatbot);
  } else {
    initGravChatbot();
  }
})();
