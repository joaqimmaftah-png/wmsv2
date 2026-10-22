const fs = require('fs');
const { spawn } = require('child_process');
const assert = require('assert');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Reuse authenticated Supervisor session cookie directly from /tmp/cookies.txt (zero login form race)
const cookieLines = fs.readFileSync('/tmp/cookies.txt', 'utf8').split(/\r?\n/);
const cookiesToSet = [];
for (const rawLine of cookieLines) {
  const line = rawLine.trim().replace(/^#HttpOnly_/, '');
  if (!line || line.startsWith('#')) continue;
  const parts = line.split('\t');
  if (parts.length >= 7) {
    cookiesToSet.push({
      name: parts[5], value: parts[6], domain: '127.0.0.1', path: parts[2] || '/'
    });
  }
}

(async () => {
  const chrome = spawn('google-chrome', [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--no-first-run', '--no-default-browser-check', '--user-data-dir=/tmp/wms-chrome-profile',
    '--remote-allow-origins=*', '--remote-debugging-port=9222',
    '--window-size=1440,900', 'about:blank'
  ], { stdio: 'ignore' });

  try {
    let targets = null;
    for (let i = 0; i < 80; i++) {
      try {
        const r = await fetch('http://127.0.0.1:9222/json');
        const j = await r.json();
        if (Array.isArray(j) && j.find((t) => t.type === 'page')) { targets = j; break; }
      } catch (_) {}
      await sleep(250);
    }
    assert(targets, 'Chrome DevTools endpoint failed to start');
    const page = targets.find((t) => t.type === 'page');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let seq = 0;
    const pending = new Map();
    ws.onmessage = (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && pending.has(msg.id)) {
        const { resolve, reject } = pending.get(msg.id);
        pending.delete(msg.id);
        msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result);
      }
    };
    const cdp = (method, params = {}) => new Promise((resolve, reject) => {
      const id = ++seq;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });
    const evalJs = async (expression) => {
      const res = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (res.exceptionDetails) throw new Error('JS Exception: ' + JSON.stringify(res.exceptionDetails));
      return res.result ? res.result.value : undefined;
    };

    await cdp('Network.enable');
    for (const ck of cookiesToSet) {
      await cdp('Network.setCookie', ck);
    }
    await cdp('Page.enable');
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/team/report' });
    for (let i = 0; i < 50; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.querySelector(".ts-row-chk"))')) break;
      await sleep(200);
    }

    // 1. Verify Desktop 1440px layout has ZERO horizontal scrollbar overflow & interactive controls work
    const desktopCheck = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.ts-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Keyboard '/' focuses search input
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement && document.activeElement.id === 'tsSearchInput');
      document.activeElement.blur();

      // Select first worker checkbox -> batch bar appears
      const chk = document.querySelector('.ts-row-chk');
      chk.checked = true;
      chk.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = !document.getElementById('tsBatchBar').hidden;
      const batchHref = document.getElementById('tsBatchPdf').getAttribute('href');

      // Click worker name -> right-side Slide-Over AML Sheet Drawer opens & fetches live JSON
      const trigger = document.querySelector('.ts-worker-name.js-open-sheet');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('tsDrawerBody').textContent.includes('WEEK 1')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = !document.getElementById('tsDrawer').hidden;
      const drawerHasWeeks = document.getElementById('tsDrawerBody').textContent.includes('WEEK 1');
      const firstTitle = document.getElementById('tsDrawerTitle').textContent;

      // ArrowRight steps to next worker inside open drawer
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 300));
      const secondTitle = document.getElementById('tsDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger element
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = document.getElementById('tsDrawer').hidden;
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly without reload
      document.querySelector('[data-set-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.ts-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.ts-table-wrap')).display === 'none';
      document.querySelector('[data-set-view="table"]').click();

      return {
        docOverflow, tblOverflow, slashFocused, batchVisible, batchHref,
        drawerOpen, drawerHasWeeks, stepped: firstTitle !== secondTitle,
        drawerClosed, focusRestored, cardsShown
      };
    })()`);

    assert(desktopCheck.docOverflow <= 0, 'Desktop viewport overflowed horizontally: ' + desktopCheck.docOverflow);
    assert(desktopCheck.tblOverflow <= 0, 'Desktop table wrapper overflowed horizontally: ' + desktopCheck.tblOverflow);
    assert(desktopCheck.slashFocused === true, 'Slash shortcut failed to focus search input');
    assert(desktopCheck.batchVisible === true && desktopCheck.batchHref.includes('ids='), 'Batch selection bar failed');
    assert(desktopCheck.drawerOpen && desktopCheck.drawerHasWeeks, 'Slide-over AML drawer failed to render weekly rows');
    assert(desktopCheck.stepped === true, 'ArrowRight navigation inside drawer did not advance to next worker');
    assert(desktopCheck.drawerClosed && desktopCheck.focusRestored, 'Escape key failed to close drawer & restore focus');
    assert(desktopCheck.cardsShown === true, 'View mode switcher failed to display Card View');

    // 2. Verify Mobile 390x844 responsive auto-card adaptation & zero horizontal overflow
    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/team/report' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("tsShell"))')) break;
      await sleep(200);
    }
    const mobileCheck = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.ts-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.ts-cards-container')).display
    }))()`);
    assert(mobileCheck.docOverflow <= 0, 'Mobile 390px viewport overflowed horizontally: ' + mobileCheck.docOverflow);
    assert(mobileCheck.tableDisplay === 'none' && mobileCheck.cardsDisplay === 'block', 'Mobile responsive Card View did not activate: ' + JSON.stringify(mobileCheck));

    // 3. Verify Desktop 1440x900 & Mobile 390x844 UI, Contrast & Drawer on /team
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/team?sort=active_wos&dir=desc' });
    for (let i = 0; i < 50; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.querySelector(".tm-row-chk"))')) break;
      await sleep(200);
    }

    const teamDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.tm-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Measure live WCAG contrast ratio of .tm-avatar foreground vs background
      function relLum(rgbStr) {
        const m = rgbStr.match(/\\d+/g).map(Number).slice(0, 3).map((v) => {
          const s = v / 255;
          return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2];
      }
      const av = document.querySelector('.tm-avatar');
      const avStyle = getComputedStyle(av);
      const l1 = relLum(avStyle.color), l2 = relLum(avStyle.backgroundColor);
      const avatarContrast = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);

      // Slash shortcut focuses #tmSearchInput
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement && document.activeElement.id === 'tmSearchInput');
      document.activeElement.blur();

      // Multi-select checkbox reveals batch bar
      const chk = document.querySelector('.tm-row-chk');
      chk.checked = true;
      chk.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = !document.getElementById('tmBatchBar').hidden;
      const batchCsvHref = document.getElementById('tmBatchCsv').getAttribute('href');

      // Open Staff Inspector Drawer on first row (John Moshi with WO-CI-TEAM-01)
      const trigger = document.querySelector('.tm-staff-btn.js-open-member');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('tmDrawerBody').textContent.includes('WO-CI-TEAM-01')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = !document.getElementById('tmDrawer').hidden;
      const drawerText = document.getElementById('tmDrawerBody').textContent;
      const hasWoAndCert = drawerText.includes('WO-CI-TEAM-01') && drawerText.includes('EX 006') && drawerText.includes('Heavy Excavator Safety');
      const firstName = document.getElementById('tmDrawerTitle').textContent;

      // ArrowRight steps to next staff member
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondName = document.getElementById('tmDrawerTitle').textContent;

      // Escape closes drawer and restores focus
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = document.getElementById('tmDrawer').hidden;
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view
      document.querySelector('[data-set-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.tm-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.tm-table-wrap')).display === 'none';
      document.querySelector('[data-set-view="table"]').click();

      return {
        docOverflow, tblOverflow, avatarContrast, slashFocused, batchVisible, batchCsvHref,
        drawerOpen, hasWoAndCert, stepped: firstName !== secondName,
        drawerClosed, focusRestored, cardsShown
      };
    })()`);

    assert(teamDesktop.docOverflow <= 0, '/team Desktop viewport overflowed: ' + teamDesktop.docOverflow);
    assert(teamDesktop.tblOverflow <= 0, '/team Desktop table overflowed: ' + teamDesktop.tblOverflow);
    assert(teamDesktop.avatarContrast >= 4.5, '/team Avatar contrast failed WCAG AA: ' + teamDesktop.avatarContrast);
    assert(teamDesktop.slashFocused === true, '/team Slash shortcut failed');
    assert(teamDesktop.batchVisible && teamDesktop.batchCsvHref.includes('ids='), '/team Batch bar failed');
    assert(teamDesktop.drawerOpen && teamDesktop.hasWoAndCert, '/team Drawer missed WO-CI-TEAM-01 / EX 006 / Cert');
    assert(teamDesktop.stepped === true, '/team ArrowRight drawer navigation failed');
    assert(teamDesktop.drawerClosed && teamDesktop.focusRestored, '/team Escape close / focus restore failed');
    assert(teamDesktop.cardsShown === true, '/team Cards view switcher failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/team' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("tmShell"))')) break;
      await sleep(200);
    }
    const teamMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.tm-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.tm-cards-container')).display
    }))()`);
    assert(teamMobile.docOverflow <= 0, '/team Mobile 390px overflowed: ' + teamMobile.docOverflow);
    assert(teamMobile.tableDisplay === 'none' && teamMobile.cardsDisplay === 'block', '/team Mobile Card View failed: ' + JSON.stringify(teamMobile));

    // 4. Verify Desktop 1440x900 & Mobile 390x844 UI, Overflow, Batch Bar & Drawer on /workorders
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/workorders?sort=wo_number&dir=asc' });
    for (let i = 0; i < 50; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.querySelector(".wo-row-chk"))')) break;
      await sleep(200);
    }

    const woDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.wo-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Slash shortcut focuses #woSearchInput
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement && document.activeElement.id === 'woSearchInput');
      document.activeElement.blur();

      // Select first row -> batch bar opens with exact ?ids=
      const chk = document.querySelector('.wo-row-chk');
      chk.checked = true;
      chk.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = !document.getElementById('woBatchBar').hidden;
      const batchCsvHref = document.getElementById('woBatchCsv').getAttribute('href');

      // Click first row WO number (WO-CI-HOLD-02) -> Slide-Over Work Order Inspector Drawer opens
      const trigger = document.querySelector('.wo-id-btn.js-open-wo');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('woDrawerBody').textContent.includes('Hydraulic Boom Seal Kit')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = !document.getElementById('woDrawer').hidden;
      const drawerText = document.getElementById('woDrawerBody').textContent;
      const hasPartAndHold = drawerText.includes('Hydraulic Boom Seal Kit') && drawerText.includes('Waiting for Spare Part');
      const firstHeader = document.getElementById('woDrawerTitle').textContent;

      // ArrowRight advances drawer to WO-CI-TEAM-01
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondHeader = document.getElementById('woDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger button
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = document.getElementById('woDrawer').hidden;
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly
      document.querySelector('[data-set-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.wo-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.wo-table-wrap')).display === 'none';
      document.querySelector('[data-set-view="table"]').click();

      return {
        docOverflow, tblOverflow, slashFocused, batchVisible, batchCsvHref,
        drawerOpen, hasPartAndHold, stepped: firstHeader !== secondHeader,
        drawerClosed, focusRestored, cardsShown
      };
    })()`);

    assert(woDesktop.docOverflow <= 0, '/workorders Desktop overflowed: ' + woDesktop.docOverflow);
    assert(woDesktop.tblOverflow <= 0, '/workorders Table wrapper overflowed: ' + woDesktop.tblOverflow);
    assert(woDesktop.slashFocused === true, '/workorders Slash shortcut failed');
    assert(woDesktop.batchVisible && woDesktop.batchCsvHref.includes('ids='), '/workorders Batch bar failed');
    assert(woDesktop.drawerOpen && woDesktop.hasPartAndHold, '/workorders Drawer missed part/hold details');
    assert(woDesktop.stepped === true, '/workorders ArrowRight navigation failed');
    assert(woDesktop.drawerClosed && woDesktop.focusRestored, '/workorders Escape close/focus failed');
    assert(woDesktop.cardsShown === true, '/workorders Cards switcher failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/workorders' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("woShell"))')) break;
      await sleep(200);
    }
    const woMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.wo-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.wo-cards-container')).display
    }))()`);
    assert(woMobile.docOverflow <= 0, '/workorders Mobile 390px overflowed: ' + woMobile.docOverflow);
    assert(woMobile.tableDisplay === 'none' && woMobile.cardsDisplay === 'block', '/workorders Mobile Card View failed: ' + JSON.stringify(woMobile));

    // ---------------------------------------------------------------
    // /equipment Enterprise Fleet DataTable Desktop (1440x900) & Mobile (390x844) E2E
    // ---------------------------------------------------------------
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/equipment' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("eqShell"))')) break;
      await sleep(200);
    }
    const eqDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.eq-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Slash shortcut focuses search input
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement === document.getElementById('eqSearchInput'));
      document.activeElement.blur();

      // Select first row checkbox -> opens sticky batch bar with ?ids=
      const firstCb = document.querySelector('.eq-row-check');
      firstCb.checked = true;
      firstCb.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = document.getElementById('eqBatchBar').classList.contains('is-open');
      const batchCsvHref = document.getElementById('eqBatchExportBtn').getAttribute('href') || '';

      // Open Slide-Over Machine Inspector Drawer
      const trigger = document.querySelector('.eq-inspect-trigger');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('eqDrawerBody').textContent.includes('Asset Register Details')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = document.getElementById('eqDrawer').classList.contains('is-open');
      const hasAssetDetails = document.getElementById('eqDrawerBody').textContent.includes('Current Hours')
        && document.getElementById('eqDrawerBody').textContent.includes('Current KM');
      const firstHeader = document.getElementById('eqDrawerTitle').textContent;

      // ArrowRight steps drawer to next fleet row
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondHeader = document.getElementById('eqDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger button
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = !document.getElementById('eqDrawer').classList.contains('is-open');
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly
      document.querySelector('[data-eq-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.eq-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.eq-table-wrap')).display === 'none';
      document.querySelector('[data-eq-view="table"]').click();

      // Verify live CSV export endpoint returns UTF-8 BOM + exact Asset Register headers
      const csvRes = await fetch('/equipment/export_csv?per_page=10', { credentials: 'same-origin' });
      const csvText = await csvRes.text();
      const csvOk = csvRes.status === 200
        && csvText.includes('Machine ID')
        && csvText.includes('Type,Category,Brand,Model,Year')
        && csvText.includes('Current Hours')
        && csvText.includes('Current KM');

      return {
        docOverflow, tblOverflow, slashFocused, batchVisible, batchCsvHref,
        drawerOpen, hasAssetDetails, stepped: firstHeader !== secondHeader,
        drawerClosed, focusRestored, cardsShown, csvOk
      };
    })()`);

    assert(eqDesktop.docOverflow <= 0, '/equipment Desktop overflowed: ' + eqDesktop.docOverflow);
    assert(eqDesktop.tblOverflow <= 0, '/equipment Table wrapper overflowed: ' + eqDesktop.tblOverflow);
    assert(eqDesktop.slashFocused === true, '/equipment Slash shortcut failed');
    assert(eqDesktop.batchVisible && eqDesktop.batchCsvHref.includes('ids='), '/equipment Batch bar failed');
    assert(eqDesktop.drawerOpen && eqDesktop.hasAssetDetails, '/equipment Drawer missed Asset Register details');
    assert(eqDesktop.stepped === true, '/equipment ArrowRight navigation failed');
    assert(eqDesktop.drawerClosed && eqDesktop.focusRestored, '/equipment Escape close/focus failed');
    assert(eqDesktop.cardsShown === true, '/equipment Cards switcher failed');
    assert(eqDesktop.csvOk === true, '/equipment CSV export failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/equipment' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("eqShell"))')) break;
      await sleep(200);
    }
    const eqMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.eq-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.eq-cards-container')).display
    }))()`);
    assert(eqMobile.docOverflow <= 0, '/equipment Mobile 390px overflowed: ' + eqMobile.docOverflow);
    assert(eqMobile.tableDisplay === 'none' && eqMobile.cardsDisplay === 'block', '/equipment Mobile Card View failed: ' + JSON.stringify(eqMobile));

    // ---------------------------------------------------------------
    // /pm Enterprise PM Planner Desktop (1440x900) & Mobile (390x844) E2E
    // ---------------------------------------------------------------
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/pm' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("pmShell"))')) break;
      await sleep(200);
    }
    const pmDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.pm-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Slash shortcut focuses search input
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement === document.getElementById('pmSearchInput'));
      document.activeElement.blur();

      // Select first row checkbox -> opens sticky batch bar with ?ids=
      const firstCb = document.querySelector('.pm-row-check');
      firstCb.checked = true;
      firstCb.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = document.getElementById('pmBatchBar').classList.contains('is-open');
      const batchCsvHref = document.getElementById('pmBatchExportBtn').getAttribute('href') || '';

      // Open Slide-Over PM Schedule Inspector Drawer
      const trigger = document.querySelector('.pm-inspect-trigger');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('pmDrawerBody').textContent.includes('Schedule Parameters')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = document.getElementById('pmDrawer').classList.contains('is-open');
      const hasParams = document.getElementById('pmDrawerBody').textContent.includes('Standard Hours (F4)');
      const firstHeader = document.getElementById('pmDrawerTitle').textContent;

      // ArrowRight steps drawer to next PM schedule
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondHeader = document.getElementById('pmDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger button
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = !document.getElementById('pmDrawer').classList.contains('is-open');
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly
      document.querySelector('[data-pm-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.pm-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.pm-table-wrap')).display === 'none';
      document.querySelector('[data-pm-view="table"]').click();

      // Verify live CSV export endpoint returns 200 + PM Schedule columns
      const csvRes = await fetch('/pm/export_csv?per_page=10', { credentials: 'same-origin' });
      const csvText = await csvRes.text();
      const csvOk = csvRes.status === 200
        && csvText.includes('Schedule ID')
        && csvText.includes('Compliance Status')
        && csvText.includes('250-Hour Engine Service');

      return {
        docOverflow, tblOverflow, slashFocused, batchVisible, batchCsvHref,
        drawerOpen, hasParams, stepped: firstHeader !== secondHeader,
        drawerClosed, focusRestored, cardsShown, csvOk
      };
    })()`);

    assert(pmDesktop.docOverflow <= 0, '/pm Desktop overflowed: ' + pmDesktop.docOverflow);
    assert(pmDesktop.tblOverflow <= 0, '/pm Table wrapper overflowed: ' + pmDesktop.tblOverflow);
    assert(pmDesktop.slashFocused === true, '/pm Slash shortcut failed');
    assert(pmDesktop.batchVisible && pmDesktop.batchCsvHref.includes('ids='), '/pm Batch bar failed');
    assert(pmDesktop.drawerOpen && pmDesktop.hasParams, '/pm Drawer missed Schedule Parameters');
    assert(pmDesktop.stepped === true, '/pm ArrowRight navigation failed');
    assert(pmDesktop.drawerClosed && pmDesktop.focusRestored, '/pm Escape close/focus failed');
    assert(pmDesktop.cardsShown === true, '/pm Cards switcher failed');
    assert(pmDesktop.csvOk === true, '/pm CSV export failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/pm' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("pmShell"))')) break;
      await sleep(200);
    }
    const pmMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.pm-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.pm-cards-container')).display
    }))()`);
    assert(pmMobile.docOverflow <= 0, '/pm Mobile 390px overflowed: ' + pmMobile.docOverflow);
    assert(pmMobile.tableDisplay === 'none' && pmMobile.cardsDisplay === 'block', '/pm Mobile Card View failed: ' + JSON.stringify(pmMobile));

    // ---------------------------------------------------------------
    // /inspections Enterprise Register & Bilingual Form Hub E2E
    // ---------------------------------------------------------------
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/inspections' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("inShell"))')) break;
      await sleep(200);
    }
    const inDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.in-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Slash shortcut focuses #inSearchInput
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement === document.getElementById('inSearchInput'));
      document.activeElement.blur();

      // Quick + New Inspection launcher modal opens & Escape restores focus
      const launchBtn = document.getElementById('inOpenLauncherBtn');
      launchBtn.click();
      const modalOpened = document.getElementById('inNewModal').classList.contains('is-open')
        && document.querySelectorAll('.in-modal-item').length >= 14;
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const modalClosed = !document.getElementById('inNewModal').classList.contains('is-open')
        && (document.activeElement === launchBtn);

      // Select first row checkbox -> opens sticky batch bar with ?ids=
      const firstCb = document.querySelector('.in-row-check');
      firstCb.checked = true;
      firstCb.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = document.getElementById('inBatchBar').classList.contains('is-open');
      const batchCsvHref = document.getElementById('inBatchExportBtn').getAttribute('href') || '';

      // Open Slide-Over Inspection Summary Drawer
      const trigger = document.querySelector('.in-inspect-trigger');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('inDrawerBody').textContent.includes('Inspection Parameters')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = document.getElementById('inDrawer').classList.contains('is-open');
      const hasFailedSection = document.getElementById('inDrawerBody').textContent.includes('Hydraulic brake line seepage');
      const firstHeader = document.getElementById('inDrawerTitle').textContent;

      // ArrowRight steps drawer to second record (INSP-0952)
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondHeader = document.getElementById('inDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger button
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = !document.getElementById('inDrawer').classList.contains('is-open');
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly
      document.querySelector('[data-in-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.in-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.in-table-wrap')).display === 'none';
      document.querySelector('[data-in-view="table"]').click();

      // Verify live UTF-8 BOM CSV export endpoint returns 200 + verdict columns
      const csvRes = await fetch('/inspections/export_csv?per_page=10', { credentials: 'same-origin' });
      const csvText = await csvRes.text();
      const csvOk = csvRes.status === 200
        && csvText.includes('Record ID')
        && csvText.includes('INSP-951')
        && csvText.includes('INSP-952');

      return {
        docOverflow, tblOverflow, slashFocused, modalOpened, modalClosed,
        batchVisible, batchCsvHref, drawerOpen, hasFailedSection,
        stepped: firstHeader !== secondHeader, drawerClosed, focusRestored,
        cardsShown, csvOk
      };
    })()`);

    assert(inDesktop.docOverflow <= 0, '/inspections Desktop overflowed: ' + inDesktop.docOverflow);
    assert(inDesktop.tblOverflow <= 0, '/inspections Table wrapper overflowed: ' + inDesktop.tblOverflow);
    assert(inDesktop.slashFocused === true, '/inspections Slash shortcut failed');
    assert(inDesktop.modalOpened && inDesktop.modalClosed, '/inspections New Inspection launcher modal failed');
    assert(inDesktop.batchVisible && inDesktop.batchCsvHref.includes('ids='), '/inspections Batch bar failed');
    assert(inDesktop.drawerOpen && inDesktop.hasFailedSection, '/inspections Drawer missed failed item');
    assert(inDesktop.stepped === true, '/inspections ArrowRight navigation failed');
    assert(inDesktop.drawerClosed && inDesktop.focusRestored, '/inspections Escape close/focus failed');
    assert(inDesktop.cardsShown === true, '/inspections Cards switcher failed');
    assert(inDesktop.csvOk === true, '/inspections CSV export failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/inspections' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("inShell"))')) break;
      await sleep(200);
    }
    const inMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.in-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.in-cards-container')).display
    }))()`);
    assert(inMobile.docOverflow <= 0, '/inspections Mobile 390px overflowed: ' + inMobile.docOverflow);
    assert(inMobile.tableDisplay === 'none' && inMobile.cardsDisplay === 'block', '/inspections Mobile Card View failed: ' + JSON.stringify(inMobile));

    // ---------------------------------------------------------------
    // /backlog Enterprise Defect Register Desktop (1440x900) & Mobile (390x844) E2E
    // ---------------------------------------------------------------
    await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/backlog' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("blShell"))')) break;
      await sleep(200);
    }
    const blDesktop = await evalJs(`(async () => {
      const docOverflow = document.documentElement.scrollWidth - window.innerWidth;
      const tblWrap = document.querySelector('.bl-table-wrap');
      const tblOverflow = tblWrap ? (tblWrap.scrollWidth - tblWrap.clientWidth) : -1;

      // Slash shortcut focuses #blSearchInput
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
      const slashFocused = (document.activeElement === document.getElementById('blSearchInput'));
      document.activeElement.blur();

      // Supervisor Verify modal opens with target action & Escape restores focus
      const verifyBtn = document.querySelector('[data-bl-close-id]');
      verifyBtn.click();
      const closeModalOpen = document.getElementById('blCloseModal').classList.contains('is-open')
        && document.getElementById('blCloseForm').getAttribute('action').includes('backlog/close/961');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const closeModalClosed = !document.getElementById('blCloseModal').classList.contains('is-open')
        && (document.activeElement === verifyBtn);

      // Select first row checkbox -> opens sticky batch bar with ?ids=
      const firstCb = document.querySelector('.bl-row-check');
      firstCb.checked = true;
      firstCb.dispatchEvent(new Event('change', { bubbles: true }));
      const batchVisible = document.getElementById('blBatchBar').classList.contains('is-open');
      const batchCsvHref = document.getElementById('blBatchExportBtn').getAttribute('href') || '';

      // Open Slide-Over Defect Inspector Drawer (BL-0961)
      const trigger = document.querySelector('.bl-inspect-trigger');
      trigger.focus();
      trigger.click();
      for (let w = 0; w < 25; w++) {
        if (document.getElementById('blDrawerBody').textContent.includes('Traceability')) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      const drawerOpen = document.getElementById('blDrawer').classList.contains('is-open');
      const hasInspLink = document.getElementById('blDrawerBody').textContent.includes('INSP-951');
      const firstHeader = document.getElementById('blDrawerTitle').textContent;

      // ArrowRight steps drawer to BL-0962
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      await new Promise((r) => setTimeout(r, 250));
      const secondHeader = document.getElementById('blDrawerTitle').textContent;

      // Escape closes drawer and restores focus to trigger button
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const drawerClosed = !document.getElementById('blDrawer').classList.contains('is-open');
      const focusRestored = (document.activeElement === trigger);

      // Toggle Cards <-> Table view smoothly
      document.querySelector('[data-bl-view="cards"]').click();
      const cardsShown = getComputedStyle(document.querySelector('.bl-cards-container')).display !== 'none'
        && getComputedStyle(document.querySelector('.bl-table-wrap')).display === 'none';
      document.querySelector('[data-bl-view="table"]').click();

      // Verify live UTF-8 BOM CSV export endpoint returns 200 + Backlog columns
      const csvRes = await fetch('/backlog/export_csv?per_page=10', { credentials: 'same-origin' });
      const csvText = await csvRes.text();
      const csvOk = csvRes.status === 200
        && csvText.includes('Backlog ID')
        && csvText.includes('BL-961')
        && csvText.includes('BL-962');

      return {
        docOverflow, tblOverflow, slashFocused, closeModalOpen, closeModalClosed,
        batchVisible, batchCsvHref, drawerOpen, hasInspLink,
        stepped: firstHeader !== secondHeader, drawerClosed, focusRestored,
        cardsShown, csvOk
      };
    })()`);

    assert(blDesktop.docOverflow <= 0, '/backlog Desktop overflowed: ' + blDesktop.docOverflow);
    assert(blDesktop.tblOverflow <= 0, '/backlog Table wrapper overflowed: ' + blDesktop.tblOverflow);
    assert(blDesktop.slashFocused === true, '/backlog Slash shortcut failed');
    assert(blDesktop.closeModalOpen && blDesktop.closeModalClosed, '/backlog Supervisor Verify modal failed');
    assert(blDesktop.batchVisible && blDesktop.batchCsvHref.includes('ids='), '/backlog Batch bar failed');
    assert(blDesktop.drawerOpen && blDesktop.hasInspLink, '/backlog Drawer missed INSP-951 link');
    assert(blDesktop.stepped === true, '/backlog ArrowRight navigation failed');
    assert(blDesktop.drawerClosed && blDesktop.focusRestored, '/backlog Escape close/focus failed');
    assert(blDesktop.cardsShown === true, '/backlog Cards switcher failed');
    assert(blDesktop.csvOk === true, '/backlog CSV export failed');

    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await cdp('Page.navigate', { url: 'http://127.0.0.1:8000/backlog' });
    for (let i = 0; i < 40; i++) {
      if (await evalJs('document.readyState === "complete" && Boolean(document.getElementById("blShell"))')) break;
      await sleep(200);
    }
    const blMobile = await evalJs(`(() => ({
      docOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableDisplay: getComputedStyle(document.querySelector('.bl-table-wrap')).display,
      cardsDisplay: getComputedStyle(document.querySelector('.bl-cards-container')).display
    }))()`);
    assert(blMobile.docOverflow <= 0, '/backlog Mobile 390px overflowed: ' + blMobile.docOverflow);
    assert(blMobile.tableDisplay === 'none' && blMobile.cardsDisplay === 'block', '/backlog Mobile Card View failed: ' + JSON.stringify(blMobile));

    ws.close();
    console.log('ALL HEADLESS CHROME DESKTOP (1440x900) & MOBILE (390x844) UI E2E ASSERTIONS PASSED!');
  } catch (err) {
    console.error('::error title=ChromeUIE2E::' + (err && err.stack ? err.stack.replace(/\n/g, '%0A') : String(err)));
    process.exitCode = 1;
  } finally {
    chrome.kill('SIGKILL');
  }
})();
