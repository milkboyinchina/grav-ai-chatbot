(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const config = window.GravChatbotConfig || {};
    const apiEndpoint = config.apiEndpoint || '/api/chatbot/query';

    const toggleBtn = document.getElementById('grav-chatbot-toggle');
    const closeBtn = document.getElementById('grav-chatbot-close');
    const windowBox = document.getElementById('grav-chatbot-window');
    const messagesFeed = document.getElementById('grav-chatbot-messages');
    const inputForm = document.getElementById('grav-chatbot-form');
    const inputField = document.getElementById('grav-chatbot-input');
    const iconChat = toggleBtn ? toggleBtn.querySelector('.grav-chatbot-icon-chat') : null;
    const iconClose = toggleBtn ? toggleBtn.querySelector('.grav-chatbot-icon-close') : null;

    let history = [];

    if (!toggleBtn || !windowBox) return;

    function toggleChat(show) {
      const isVisible = show !== undefined ? show : windowBox.style.display === 'none';
      windowBox.style.display = isVisible ? 'flex' : 'none';

      if (iconChat && iconClose) {
        iconChat.style.display = isVisible ? 'none' : 'block';
        iconClose.style.display = isVisible ? 'block' : 'none';
      }

      if (isVisible && inputField) {
        inputField.focus();
      }
    }

    toggleBtn.addEventListener('click', function () {
      toggleChat();
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        toggleChat(false);
      });
    }

    function appendMessage(role, text, source) {
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

      msgDiv.appendChild(bubbleDiv);
      messagesFeed.appendChild(msgDiv);
      messagesFeed.scrollTop = messagesFeed.scrollHeight;

      history.push({ role: role, content: text });
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

      if (action !== 'summarize_page') {
        appendMessage('user', questionText);
        if (inputField) inputField.value = '';
      } else {
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

    // Quick Action Pill Delegation
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

    function escapeAndFormatMarkdown(str) {
      let safe = str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
      
      // Bold formatting **text**
      safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
      // Newlines
      safe = safe.replace(/\n/g, '<br/>');
      return safe;
    }
  });
})();
