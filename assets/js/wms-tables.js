/* ════════════════════════════════════════════════════════════════
   WMS — data-table behaviors for forecast surfaces
   Dependency-free vanilla JS. Enhances a server-rendered page;
   everything below degrades gracefully (the page works without it).
════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  /* ── Escape helper (HTML attributes / text) ──────────────────── */

  function escHtml(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /* ── Forecast machine grid: status tabs + client search ────────
       Filtering happens in the browser. Counts published by the
       server cover the whole fleet for the week; a caption makes
       that explicit so no one confuses a tab count with a filtered
       count. Nothing is posted — the backend contract is untouched.
  ─────────────────────────────────────────────────────────────── */

  function initForecastFilters() {
    var grid = document.getElementById('forecast-machines');
    if (!grid) return;

    var tablist = grid.querySelector('[role="tablist"]');
    var search = document.getElementById('forecast-search');
    var emptyState = document.getElementById('forecast-empty');
    var resetLink = document.getElementById('forecast-reset');
    var countCaps = grid.querySelectorAll('[data-count-for]');
    var weekInput = document.querySelector('input[name="week"]');
    var week = (weekInput && weekInput.value) || '';
    var storageKey = 'wms:forecast:filter:' + week;

    var rows = Array.prototype.slice.call(
      grid.querySelectorAll('tbody tr[data-status]')
    );
    var groupCards = Array.prototype.slice.call(
      grid.querySelectorAll('.forecast-group-card')
    );

    var counts = {};
    try {
      counts = JSON.parse(grid.getAttribute('data-counts') || '{}');
    } catch (e) {
      counts = {};
    }
    var state = { status: 'all', q: '', counts: counts };
    var stored = null;
    try {
      stored = JSON.parse(sessionStorage.getItem(storageKey));
    } catch (e) {
      stored = null;
    }
    if (stored && stored.status) state.status = stored.status;
    if (stored && typeof stored.q === 'string') state.q = stored.q;

    function save() {
      try {
        sessionStorage.setItem(storageKey, JSON.stringify(state));
      } catch (e) {
        // storage unavailable — filtering still works, just not persisted
      }
    }

    function activeTab() {
      return tablist.querySelector('[role="tab"][data-status="' + escHtml(state.status) + '"]');
    }

    function setStatus(status, fromUser) {
      state.status = status;
      if (fromUser) save();
      var tabs = tablist.querySelectorAll('[role="tab"]');
      for (var i = 0; i < tabs.length; i++) {
        var pressed = tabs[i].getAttribute('data-status') === status;
        tabs[i].setAttribute('aria-pressed', pressed ? 'true' : 'false');
      }
      render();
    }

    function setQuery(q) {
      state.q = q;
      save();
      render();
    }

    function render() {
      var status = state.status;
      var q = state.q;
      var visible = 0;

      rows.forEach(function (row) {
        var show =
          (status === 'all' || row.getAttribute('data-status') === status) &&
          (!q ||
            row.getAttribute('data-registration').toLowerCase().indexOf(q) !== -1 ||
            row.getAttribute('data-location').toLowerCase().indexOf(q) !== -1);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      groupCards.forEach(function (card) {
        var tbody = card.querySelector('tbody');
        var cardVisible = 0;
        if (tbody) {
          for (var i = 0; i < tbody.rows.length; i++) {
            if (tbody.rows[i].style.display !== 'none') cardVisible++;
          }
        }
        card.style.display = cardVisible ? '' : 'none';
      });

      if (emptyState) {
        var showingFilter = status !== 'all' || q !== '';
        emptyState.style.display = showingFilter && visible === 0 ? '' : 'none';
      }

      for (var i = 0; i < countCaps.length; i++) {
        var cap = countCaps[i];
        var key = cap.getAttribute('data-count-for');
        cap.textContent = state.counts[key];
      }
    }

    /* Wire tabs (keyboard: Enter / Space also activate). */
    if (tablist) {
      var tabs = tablist.querySelectorAll('[role="tab"]');
      for (var i = 0; i < tabs.length; i++) {
        (function (tab) {
          tab.addEventListener('click', function (e) {
            e.preventDefault();
            setStatus(tab.getAttribute('data-status'), true);
          });
          tab.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              setStatus(tab.getAttribute('data-status'), true);
            }
          });
        })(tabs[i]);
      }
      setStatus(state.status, false);
    }

    /* Wire search (client-side, on every keystroke). */
    if (search) {
      search.value = state.q;
      search.addEventListener('input', function () {
        setQuery(search.value.toLowerCase().trim());
      });
    }

    if (resetLink) {
      resetLink.addEventListener('click', function (e) {
        e.preventDefault();
        state = { status: 'all', q: '' };
        if (search) search.value = '';
        save();
        if (tablist) setStatus('all', false);
      });
    }

    render();
  }

  /* ── Boot ──────────────────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForecastFilters);
  } else {
    initForecastFilters();
  }
})();
