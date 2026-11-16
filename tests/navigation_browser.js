// Chromium DOM regressions for the navigation shell.
//
// Preferred (no npm dependencies):
//   CHROME_PATH=/path/to/chrome node tests/navigation_browser.js
//
// The script renders tests/navigation_fixture.php with the local PHP CLI. If
// PHP is unavailable, pre-render fixtures and point WMS_NAV_FIXTURE_DIR at them:
//   php tests/navigation_fixture.php 1 materials/inventory > role-1.html
//   WMS_NAV_FIXTURE_DIR=/tmp/fixtures CHROME_PATH=... node tests/navigation_browser.js
//
// Without Chrome the suite skips (structural coverage lives in navigation_shell.js).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const chrome = process.env.CHROME_PATH || 'google-chrome';
const required = process.env.WMS_NAV_BROWSER_REQUIRED === '1';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

const cases = [
  { role: 1, uri: 'materials/inventory', count: 31, current: '/materials/inventory', group: 'inventory', forbidden: [] },
  { role: 2, uri: 'team/report', count: 16, current: '/team/report', group: 'my_work', forbidden: ['/reports', '/forecast', '/orders', '/suppliers', '/users', '/settings', '/team/attendance', '/attendance/log'] },
  { role: 3, uri: 'spareparts', count: 13, current: '/spareparts', group: 'inventory', forbidden: ['/workorders', '/equipment', '/pm', '/inspections', '/backlog', '/reports', '/forecast', '/users', '/settings', '/team/attendance', '/attendance/log'] },
  { role: 4, uri: 'reports/weekly_w36', count: 25, current: '/reports/workshop_weekly', group: 'reports', forbidden: ['/users', '/settings', '/attendance/punch', '/team/attendance'] },
  { role: 5, uri: 'dashboard', count: 1, current: '/dashboard', group: '', forbidden: ['/workorders', '/materials', '/reports', '/team', '/users', '/settings', '/attendance'] },
];

function fixtureDir() {
  if (process.env.WMS_NAV_FIXTURE_DIR) return process.env.WMS_NAV_FIXTURE_DIR;
  const php = spawnSync('php', ['--version'], { encoding: 'utf8' });
  if (php.error || php.status !== 0) return null;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wms-nav-fixtures-'));
  for (const c of cases) {
    const out = spawnSync('php', [path.join(__dirname, 'navigation_fixture.php'), String(c.role), c.uri], { encoding: 'utf8' });
    if (out.status !== 0) throw new Error(`fixture render failed: ${out.stderr || out.stdout}`);
    fs.writeFileSync(path.join(dir, `role-${c.role}.html`), out.stdout);
  }
  return dir;
}

async function main() {
  const version = spawnSync(chrome, ['--version'], { encoding: 'utf8' });
  const dir = fixtureDir();
  if (version.error || version.status !== 0 || !dir) {
    if (required) throw new Error('Chrome and PHP (or WMS_NAV_FIXTURE_DIR) are required');
    console.log('Navigation browser checks skipped (Chrome/PHP unavailable; run navigation_shell.js for structural coverage).');
    return;
  }

  const port = 9333;
  const proc = spawn(chrome, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--no-first-run', '--no-default-browser-check', '--remote-allow-origins=*',
    `--remote-debugging-port=${port}`, '--user-data-dir=' + fs.mkdtempSync(path.join(os.tmpdir(), 'wms-nav-chrome-')),
    'about:blank',
  ], { stdio: 'ignore' });

  try {
    let target = null;
    for (let i = 0; i < 80; i++) {
      try {
        const list = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
        target = list.find(t => t.type === 'page');
        if (target) break;
      } catch (_) {}
      await sleep(200);
    }
    assert.ok(target, 'Chrome DevTools endpoint failed to start');
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
    let seq = 0;
    const pending = new Map();
    ws.onmessage = ev => {
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
    const evalJs = async expression => {
      const res = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (res.exceptionDetails) throw new Error('JS exception: ' + JSON.stringify(res.exceptionDetails));
      return res.result ? res.result.value : undefined;
    };
    const key = async (k, code, vk) => {
      const base = { key: k, code, windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk };
      await cdp('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base });
      if (k === 'Enter') await cdp('Input.dispatchKeyEvent', { type: 'char', text: '\r', unmodifiedText: '\r', ...base });
      await cdp('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    };

    await cdp('Network.enable');
    await cdp('Network.setBlockedURLs', { urls: ['http://wms.test/*', 'https://fonts.googleapis.com/*', 'https://cdn.jsdelivr.net/*'] });
    await cdp('Page.enable');

    for (const c of cases) {
      const file = path.join(dir, `role-${c.role}.html`);
      assert.ok(fs.existsSync(file), `missing fixture ${file}`);
      const settled = async () => {
        for (let i = 0; i < 60; i++) {
          if (await evalJs(`Boolean(document.getElementById('sidebar')) && document.getElementById('main-content').getAttribute('tabindex') === '-1'`)) return;
          await sleep(100);
        }
        throw new Error(`role ${c.role} shell script did not settle`);
      };
      await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
      await cdp('Page.navigate', { url: 'file://' + file });
      await settled();
      // Each role starts from a fresh preference, as a first-time visitor would.
      await evalJs(`(() => { try { localStorage.clear(); } catch (e) {} })()`);
      await cdp('Page.reload');
      await settled();
      // Measure immediately: dense registers assert table width during load, so
      // the first viewport sync must already be settled (no 240px→68px animation).

      // Laptop widths auto-compact to the rail so dense registers keep the
      // canvas width they were designed for (the /team/report overflow guard).
      const laptop = await evalJs(`(() => ({
        collapsed: document.body.classList.contains('nav-collapsed'),
        margin: getComputedStyle(document.getElementById('canvas')).marginLeft,
        expanded: document.getElementById('nav-toggle').getAttribute('aria-expanded'),
      }))()`);
      assert.equal(laptop.collapsed, true, `role ${c.role} auto-compacts at 1440px`);
      assert.equal(laptop.margin, '68px', `role ${c.role} keeps the 68px rail canvas at 1440px`);
      assert.equal(laptop.expanded, 'false', `role ${c.role} compact toggle state`);

      // Hover-to-expand: moving the pointer onto the collapsed rail reveals the
      // whole tree as an overlay (canvas margin must NOT move), opening every group
      // like the old flat rail. Leaving restores the rail and the group states,
      // and nothing about the hover is persisted.
      const moveMouse = (x, y) => cdp('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, buttons: 0 });
      const groupState = `(() => {
        var gs = [...document.querySelectorAll('#sidebar [data-nav-group]')];
        return { total: gs.length, open: gs.filter(g => g.open).length,
          visiblePanels: gs.filter(g => { var a = g.querySelector('.nav-group-panel a'); return a && a.checkVisibility(); }).length };
      })()`;
      const storedGroupsExpr = `(() => { try { return localStorage.getItem('wms.nav.groups.' + (document.getElementById('sidebar').getAttribute('data-role') || 'user')); } catch (e) { return 'err'; } })()`;
      const before = await evalJs(groupState);
      const beforeStored = await evalJs(storedGroupsExpr);
      await moveMouse(900, 300);
      await moveMouse(30, 300);
      await sleep(300); // let the width transition finish
      const hovered = await evalJs(`(() => ({
        hover: document.body.classList.contains('nav-hover-expanded'),
        width: getComputedStyle(document.getElementById('sidebar')).width,
        margin: getComputedStyle(document.getElementById('canvas')).marginLeft,
        label: getComputedStyle(document.querySelector('#sidebar .ni-label')).display,
        stored: (() => { try { return localStorage.getItem('wms.nav.collapsed'); } catch (e) { return 'err'; } })(),
        groups: ${groupState},
      }))()`);
      assert.equal(hovered.hover, true, `role ${c.role} hover expands the rail`);
      assert.equal(hovered.width, '240px', `role ${c.role} hover rail is full width`);
      assert.equal(hovered.margin, '68px', `role ${c.role} hover overlays without moving the canvas`);
      assert.notEqual(hovered.label, 'none', `role ${c.role} hover reveals labels`);
      assert.equal(hovered.stored, null, `role ${c.role} hover does not persist a rail preference`);
      assert.equal(hovered.groups.open, hovered.groups.total, `role ${c.role} hover opens every group`);
      assert.equal(hovered.groups.visiblePanels, hovered.groups.total, `role ${c.role} hover reveals every panel`);
      await moveMouse(900, 300);
      await sleep(350); // include the next-frame snapshot drop
      const left = await evalJs(`(() => ({
        hover: document.body.classList.contains('nav-hover-expanded'),
        width: getComputedStyle(document.getElementById('sidebar')).width,
        groups: ${groupState},
        storedGroups: ${storedGroupsExpr},
      }))()`);
      assert.equal(left.hover, false, `role ${c.role} leaving the rail clears hover`);
      assert.equal(left.width, '68px', `role ${c.role} rail returns to compact width`);
      assert.equal(left.groups.open, before.open, `role ${c.role} leaving restores prior group open-states`);
      assert.equal(left.storedGroups, beforeStored, `role ${c.role} hover never changes persisted group state`);

      // Wide desktops get the labelled rail by default.
      await cdp('Emulation.setDeviceMetricsOverride', { width: 1700, height: 900, deviceScaleFactor: 1, mobile: false });
      await sleep(350);
      assert.equal(await evalJs(`document.body.classList.contains('nav-collapsed')`), false, `role ${c.role} expands on wide desktop`);
      assert.equal(await evalJs(`getComputedStyle(document.getElementById('canvas')).marginLeft`), '240px', `role ${c.role} labelled rail canvas`);

      const desktop = await evalJs(`(() => {
        const sidebar = document.getElementById('sidebar');
        const links = [...sidebar.querySelectorAll('a.ni')];
        const paths = links.map(a => new URL(a.href).pathname);
        const current = sidebar.querySelectorAll('[aria-current="page"]');
        const group = sidebar.querySelector('[data-nav-group="${c.group}"]');
        const labels = links.map(a => (a.querySelector('.ni-label') || {}).textContent || '');
        return {
          role: sidebar.getAttribute('data-role'),
          count: links.length,
          paths,
          currentCount: current.length,
          currentPath: current[0] ? new URL(current[0].href).pathname : '',
          groupOpen: group ? group.open : null,
          groupExpanded: group ? group.querySelector('summary').getAttribute('aria-expanded') : null,
          labels,
          find: Boolean(document.getElementById('global-find-trigger')),
          attention: Boolean(document.getElementById('operational-attention')),
        };
      })()`);
      assert.equal(desktop.role, String(c.role), `role ${c.role} data-role`);
      assert.equal(desktop.count, c.count, `role ${c.role} leaf count`);
      assert.equal(desktop.currentCount, 1, `role ${c.role} exactly one current leaf`);
      assert.equal(desktop.currentPath, c.current, `role ${c.role} current leaf`);
      assert.ok(desktop.labels.every(label => label.trim().length > 0), `role ${c.role} leaves are labelled`);
      if (c.group) {
        assert.equal(desktop.groupOpen, true, `role ${c.role} current group expanded`);
        assert.equal(desktop.groupExpanded, 'true', `role ${c.role} current group aria-expanded`);
      }
      for (const prefix of c.forbidden) {
        assert.ok(!desktop.paths.some(p => p === prefix || p.startsWith(prefix + '/')), `role ${c.role} forbidden destination ${prefix}`);
      }
      if (c.role === 5) {
        assert.equal(desktop.find, false, 'CEO has no Global Find');
        assert.equal(desktop.attention, false, 'CEO has no operational attention');
      }

      // Explicit desktop collapse, then keyboard-operable disclosure.
      await evalJs(`document.getElementById('nav-toggle').click()`);
      assert.equal(await evalJs(`document.body.classList.contains('nav-collapsed')`), true, `role ${c.role} collapses`);
      assert.equal(await evalJs(`document.getElementById('nav-toggle').getAttribute('aria-expanded')`), 'false', `role ${c.role} collapsed toggle state`);
      await evalJs(`document.getElementById('nav-toggle').click()`);
      if (c.group) {
        await evalJs(`(() => { const s = document.querySelector('[data-nav-group="${c.group}"] summary'); s.focus(); })()`);
        const before = await evalJs(`document.querySelector('[data-nav-group="${c.group}"]').open`);
        await key('Enter', 'Enter', 13);
        const after = await evalJs(`document.querySelector('[data-nav-group="${c.group}"]').open`);
        assert.notEqual(after, before, `role ${c.role} Enter toggles disclosure`);
        await key('Enter', 'Enter', 13);
      }

      // Mobile drawer: labelled, touch-sized, modal, Escape and Tab containment.
      await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
      await sleep(120);
      assert.equal(await evalJs(`document.getElementById('nav-toggle').getAttribute('aria-expanded')`), 'false', `role ${c.role} mobile starts closed`);
      await evalJs(`document.getElementById('nav-toggle').click()`);
      const mobile = await evalJs(`(() => {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('nav-toggle');
        const close = document.getElementById('nav-close');
        const label = sidebar.querySelector('.ni-label');
        return {
          open: document.body.classList.contains('nav-open'),
          dialog: sidebar.getAttribute('role'),
          modal: sidebar.getAttribute('aria-modal'),
          inert: document.getElementById('canvas').hasAttribute('inert'),
          expanded: toggle.getAttribute('aria-expanded'),
          closeVisible: close.getClientRects().length > 0,
          labelVisible: label ? getComputedStyle(label).display !== 'none' : false,
          toggleHeight: toggle.getBoundingClientRect().height,
          summaryHeight: sidebar.querySelector('summary') ? sidebar.querySelector('summary').getBoundingClientRect().height : 44,
        };
      })()`);
      assert.equal(mobile.open, true, `role ${c.role} mobile drawer opens`);
      assert.equal(mobile.dialog, 'dialog', `role ${c.role} drawer dialog role`);
      assert.equal(mobile.modal, 'true', `role ${c.role} drawer modal`);
      assert.equal(mobile.inert, true, `role ${c.role} canvas inert`);
      assert.equal(mobile.expanded, 'true', `role ${c.role} drawer toggle state`);
      assert.equal(mobile.closeVisible, true, `role ${c.role} close visible`);
      assert.equal(mobile.labelVisible, true, `role ${c.role} mobile labels visible`);
      assert.ok(mobile.toggleHeight >= 44 && mobile.summaryHeight >= 42, `role ${c.role} touch targets`);
      await key('Escape', 'Escape', 27);
      assert.equal(await evalJs(`document.body.classList.contains('nav-open')`), false, `role ${c.role} Escape closes drawer`);
      assert.equal(await evalJs(`document.activeElement.id`), 'nav-toggle', `role ${c.role} Escape restores focus`);
      if (c.count > 1) {
        await evalJs(`document.getElementById('nav-toggle').click()`);
        await evalJs(`(() => { const nodes = [...document.querySelectorAll('#sidebar a[href],#sidebar button,#sidebar summary')].filter(n => n.getClientRects().length > 0); nodes[nodes.length - 1].focus(); })()`);
        await key('Tab', 'Tab', 9);
        assert.equal(await evalJs(`document.getElementById('sidebar').contains(document.activeElement)`), true, `role ${c.role} Tab stays in drawer`);
        await key('Escape', 'Escape', 27);
      }

      // Reduced motion is honoured by the real stylesheet.
      await cdp('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
      assert.equal(await evalJs(`getComputedStyle(document.getElementById('sidebar')).transitionDuration`), '0s', `role ${c.role} reduced motion`);
      await cdp('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    }
    ws.close();
    console.log(`Navigation Chromium DOM checks passed for ${cases.length} role trees.`);
  } finally {
    proc.kill('SIGTERM');
  }
}

main().catch(err => { console.error(err); process.exit(1); });
