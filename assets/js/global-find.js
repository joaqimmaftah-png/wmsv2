/* WMS Global Find — dependency-free, server-authorized record navigation. */
(function () {
  'use strict';

  function initGlobalFind() {
    var trigger = document.getElementById('global-find-trigger');
    var root = document.getElementById('global-find');
    if (!trigger || !root) return;

    var panel = root.querySelector('.global-find-panel');
    var input = document.getElementById('global-find-input');
    var results = document.getElementById('global-find-results');
    var liveStatus = document.getElementById('global-find-status');
    var endpoint = trigger.getAttribute('data-search-url');
    var canvas = document.getElementById('canvas');
    if (!panel || !input || !results || !liveStatus || !endpoint) return;

    var isOpen = false;
    var lastFocus = null;
    var canvasOverflow = '';
    var debounceTimer = null;
    var activeController = null;
    var requestSequence = 0;
    var initialMessage = root.getAttribute('data-empty-message') || 'Search operational records.';

    var modifier = trigger.querySelector('[data-find-modifier]');
    if (modifier && /Mac|iPhone|iPad|iPod/.test(window.navigator.platform || window.navigator.userAgent)) {
      modifier.textContent = '⌘';
    }

    function element(tag, className, text) {
      var node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined && text !== null) node.textContent = String(text);
      return node;
    }

    function announce(message) {
      liveStatus.textContent = message || '';
    }

    function showState(message) {
      results.innerHTML = '';
      results.setAttribute('aria-busy', 'false');
      if (message) results.appendChild(element('div', 'global-find-state', message));
    }

    function cancelPending() {
      window.clearTimeout(debounceTimer);
      debounceTimer = null;
      requestSequence += 1;
      if (activeController) {
        activeController.abort();
        activeController = null;
      }
    }

    function closeAccountAndAttention() {
      var account = document.getElementById('tb-dropdown');
      var accountTrigger = document.getElementById('tb-user-trigger');
      var attention = document.getElementById('operational-attention');
      if (account) account.classList.remove('open');
      if (accountTrigger) accountTrigger.setAttribute('aria-expanded', 'false');
      if (attention) attention.removeAttribute('open');
    }

    function openFind() {
      if (isOpen) return;
      closeAccountAndAttention();
      lastFocus = document.activeElement;
      isOpen = true;
      root.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      if (canvas) {
        canvasOverflow = canvas.style.overflowY;
        canvas.style.overflowY = 'hidden';
      }
      input.focus();
      input.select();
    }

    function closeFind(restoreFocus) {
      if (!isOpen) return;
      cancelPending();
      isOpen = false;
      root.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      input.value = '';
      showState(initialMessage);
      announce('');
      if (canvas) canvas.style.overflowY = canvasOverflow;
      if (restoreFocus !== false) {
        var lastFocusVisible = lastFocus && lastFocus.isConnected && lastFocus.getClientRects().length > 0;
        var target = lastFocusVisible ? lastFocus : trigger;
        if (target && typeof target.focus === 'function') target.focus();
      }
    }

    function statusTone(tone) {
      return ['critical', 'warning', 'success', 'info', 'neutral'].indexOf(tone) !== -1 ? tone : 'neutral';
    }

    function resultLink(item) {
      var link = element('a', 'global-find-result');
      link.href = String(item.url || '#');

      var main = element('span', 'global-find-result-main');
      var firstLine = element('span', 'global-find-result-line');
      firstLine.appendChild(element('strong', 'global-find-identifier', item.identifier || 'Record'));
      if (item.status) {
        firstLine.appendChild(element('span', 'global-find-status global-find-status--' + statusTone(item.tone), item.status));
      }
      main.appendChild(firstLine);
      if (item.title) main.appendChild(element('span', 'global-find-result-title', item.title));
      if (item.context) main.appendChild(element('span', 'global-find-result-context', item.context));
      link.appendChild(main);
      link.appendChild(element('span', 'global-find-result-arrow', '→'));
      link.lastChild.setAttribute('aria-hidden', 'true');
      link.addEventListener('click', function () { closeFind(false); });
      return link;
    }

    function renderResults(payload, query) {
      results.innerHTML = '';
      results.setAttribute('aria-busy', 'false');
      var groups = payload && Array.isArray(payload.groups) ? payload.groups : [];
      var visibleCount = 0;

      groups.forEach(function (group) {
        if (!group || !Array.isArray(group.results) || !group.results.length) return;
        var section = element('section', 'global-find-group');
        section.appendChild(element('h3', 'global-find-group-title', group.label || 'Records'));
        var list = element('div', 'global-find-group-list');
        group.results.forEach(function (item) {
          if (!item || !item.url) return;
          list.appendChild(resultLink(item));
          visibleCount += 1;
        });
        if (list.childNodes.length) {
          section.appendChild(list);
          results.appendChild(section);
        }
      });

      if (!visibleCount) {
        showState('No records match “' + query + '”.');
        announce('No results for ' + query + '.');
        return;
      }
      announce(visibleCount + ' result' + (visibleCount === 1 ? '' : 's') + ' for ' + query + '.');
    }

    function executeSearch(query) {
      var sequence = ++requestSequence;
      if (activeController) activeController.abort();
      activeController = typeof window.AbortController === 'function' ? new window.AbortController() : null;

      if (typeof window.fetch !== 'function') {
        showState('Search is not supported by this browser.');
        announce('Search is not supported by this browser.');
        return;
      }

      results.setAttribute('aria-busy', 'true');
      showState('Searching…');
      results.setAttribute('aria-busy', 'true');
      var options = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
      if (activeController) options.signal = activeController.signal;
      var separator = endpoint.indexOf('?') === -1 ? '?' : '&';

      window.fetch(endpoint + separator + 'q=' + encodeURIComponent(query), options)
        .then(function (response) {
          if (!response.ok) throw new Error('Search request failed');
          return response.json();
        })
        .then(function (payload) {
          if (sequence !== requestSequence || !isOpen) return;
          if (!payload || payload.ok === false) throw new Error('Search request failed');
          renderResults(payload, query);
        })
        .catch(function (error) {
          if (sequence !== requestSequence || !isOpen || (error && error.name === 'AbortError')) return;
          showState('Search could not be completed. Check your connection and try again.');
          announce('Search could not be completed.');
        });
    }

    function queueSearch() {
      var query = input.value.trim();
      cancelPending();
      if (!query) {
        showState(initialMessage);
        announce('');
        return;
      }
      if (query.length < 2) {
        showState('Enter at least 2 characters.');
        announce('');
        return;
      }
      showState('Searching…');
      debounceTimer = window.setTimeout(function () { executeSearch(query); }, 180);
    }

    function focusableElements() {
      return Array.prototype.slice.call(panel.querySelectorAll('input, button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
    }

    function trapTab(event) {
      var focusable = focusableElements();
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    trigger.addEventListener('click', openFind);
    input.addEventListener('input', queueSearch);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        var first = results.querySelector('a[href]');
        if (first) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    results.addEventListener('keydown', function (event) {
      var links = Array.prototype.slice.call(results.querySelectorAll('a[href]'));
      var index = links.indexOf(document.activeElement);
      if (index === -1) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        var direction = event.key === 'ArrowDown' ? 1 : -1;
        var next = index + direction;
        if (next < 0) input.focus();
        else links[(next + links.length) % links.length].focus();
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        links[event.key === 'Home' ? 0 : links.length - 1].focus();
      }
    });

    root.addEventListener('click', function (event) {
      if (event.target.closest('[data-find-close]')) closeFind(true);
    });

    document.addEventListener('keydown', function (event) {
      var key = String(event.key || '').toLowerCase();
      if ((event.ctrlKey || event.metaKey) && !event.altKey && key === 'k') {
        event.preventDefault();
        event.stopPropagation();
        if (isOpen) closeFind(true);
        else openFind();
        return;
      }
      if (!isOpen) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        closeFind(true);
      } else if (event.key === 'Tab') {
        trapTab(event);
      } else if (event.key === '/') {
        // Keep page-local / shortcuts from moving focus behind the modal.
        event.preventDefault();
        event.stopPropagation();
        input.focus();
      }
    }, true);

    showState(initialMessage);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGlobalFind);
  } else {
    initGlobalFind();
  }
})();
