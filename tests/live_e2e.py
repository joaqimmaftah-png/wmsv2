import http.cookiejar, io, json, os, re, subprocess, tempfile, traceback, urllib.error, urllib.parse, urllib.request, zipfile, zlib

try:
    cj = http.cookiejar.MozillaCookieJar('/tmp/cookies.txt')
    cj.load(ignore_discard=True, ignore_expires=True)
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    BASE = 'http://127.0.0.1:8000'

    def get(path, op=opener):
        return op.open(BASE + path).read().decode('utf-8', 'replace')

    def get_bytes(path, op=opener):
        try:
            r = op.open(BASE + path)
            return r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read()

    def csrf_from(html):
        m = re.search(r'name=["\x27]csrf_token_wms["\x27]\s+value=["\x27]([^"\x27]+)', html)
        assert m, 'Missing csrf_token_wms in HTML'
        return m.group(1)

    def post(path, fields, ajax=False):
        data = urllib.parse.urlencode(fields).encode()
        headers = {'X-Requested-With': 'XMLHttpRequest'} if ajax else {}
        req = urllib.request.Request(BASE + path, data=data, headers=headers)
        try:
            r = opener.open(req)
            return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    # 1. Create active geofenced site with printed QR fallback enabled
    tok = csrf_from(get('/attendance/sites'))
    code, _ = post('/attendance/sites', {
        'csrf_token_wms': tok,
        'name': 'Workshop Main Gate',
        'latitude': '-6.7924123',
        'longitude': '39.2083456',
        'radius_m': '150',
        'max_accuracy_m': '100',
        'is_active': '1',
        'allow_static_qr': '1',
    })
    assert code == 200

    # 2. Enable attendance assignment gate & retention/grace policy
    tok = csrf_from(get('/attendance/sites'))
    code, _ = post('/attendance/policy', {
        'csrf_token_wms': tok,
        'attendance_gate_enabled': '1',
        'attendance_open_session_policy': 'block',
        'attendance_qr_ttl_seconds': '60',
        'attendance_qr_grace_seconds': '120',
        'attendance_location_retention_days': '365',
    })
    assert code == 200

    # 3. Before check-in, work-order picker must exclude unchecked-in technicians
    wo_create_before = get('/workorders/create')
    assert 'John Moshi' not in wo_create_before, 'Unchecked-in technician leaked into WO picker!'
    assert 'No technicians are currently checked in on site' in wo_create_before

    # 4. Issue rotating QR token; outside geofence must fail (422), inside must succeed (200)
    token_json = json.loads(get('/attendance/token'))
    assert token_json['ok'] is True
    qr_token = token_json['token']

    punch_page = get('/attendance/punch?t=' + urllib.parse.quote(qr_token))
    code422, body422 = post('/attendance/record', {
        'csrf_token_wms': csrf_from(punch_page),
        't': qr_token,
        'lat': '-6.8500000',
        'lng': '39.2083456',
        'acc': '12',
        'format': 'json',
    }, ajax=True)
    assert code422 == 422 and json.loads(body422)['ok'] is False

    punch_page = get('/attendance/punch?t=' + urllib.parse.quote(qr_token))
    code200, body200 = post('/attendance/record', {
        'csrf_token_wms': csrf_from(punch_page),
        't': qr_token,
        'lat': '-6.7924200',
        'lng': '39.2083500',
        'acc': '12',
        'format': 'json',
    }, ajax=True)
    res200 = json.loads(body200)
    assert code200 == 200 and res200['ok'] is True and res200['punch'] == 'check_in', body200

    # 5. Checked-in user now appears in WO picker while unchecked-in John Moshi stays excluded
    wo_create_after = get('/workorders/create')
    assert 'CI Supervisor' in wo_create_after, 'Checked-in user missing from WO picker!'
    assert 'John Moshi' not in wo_create_after, 'Unchecked-in technician leaked into WO picker!'

    # 6. Printable fixed QR poster renders valid v1.<site>.0.<mac> token
    print_html = get('/attendance/print_qr')
    assert re.search(r'v1\.\d+\.0\.[0-9a-f]{32}', print_html), 'Static printed QR token missing!'

    # 7. Live AML Individual Attendance Sheet E2E checks (Supervisor, Manager & Technician RBAC)
    def pdf_streams(raw):
        out = []
        for m in re.finditer(b'stream\r?\n(.*?)\r?\n?endstream', raw, re.S):
            try:
                out.append(zlib.decompress(m.group(1)))
            except Exception:
                out.append(m.group(1))
        return b'\n'.join(out)

    rep_html = get('/team/report')
    assert 'All PDF Sheets' in rep_html and 'Workshop Main Gate' in rep_html, 'Live QR site missing from /team/report!'
    assert 'role="tablist"' in rep_html and 'id="tsDrawer"' in rep_html, 'Enterprise DataTable tabs/drawer missing!'
    assert '16/Jul/26' not in rep_html and 'MONTH OF JULY 2026' not in rep_html

    m_sup  = re.search(r'data-worker-row="(\d+)"[^>]*data-worker-name="CI Supervisor"', rep_html)
    m_tech = re.search(r'data-worker-row="(\d+)"[^>]*data-worker-name="John Moshi"', rep_html)
    assert m_sup and m_tech, 'Expected both CI Supervisor and John Moshi in /team/report'
    sup_id, tech_id = m_sup.group(1), m_tech.group(1)

    # Verify sorting, search, status tabs, card view & pagination query handling
    sorted_html = get('/team/report?sort=hours&dir=desc&tab=recorded&per_page=10&page=1')
    assert 'aria-sort="descending"' in sorted_html and 'CI Supervisor' in sorted_html
    search_html = get('/team/report?q=Moshi&view=cards')
    assert 'John Moshi' in search_html and 'data-worker-name="CI Supervisor"' not in search_html and 'ts-mode-cards' in search_html

    # Verify JSON Drawer endpoint for live weekly AML sheet inspection
    st_js, _, js_raw = get_bytes(f'/team/sheet_json/{sup_id}?period=full')
    js_doc = json.loads(js_raw.decode('utf-8'))
    assert st_js == 200 and js_doc.get('ok') is True and js_doc.get('full_name') == 'CI Supervisor'
    assert js_doc.get('site') == 'Workshop Main Gate' and len(js_doc.get('segments', [])) == 2

    # Verify Summary CSV export (UTF-8 BOM, filtered columns & multi-select ids)
    st_csv, _, csv_raw = get_bytes(f'/team/report_csv?ids={sup_id}')
    assert st_csv == 200 and csv_raw.startswith(b'\xef\xbb\xbf'), 'CSV missing UTF-8 BOM!'
    csv_txt = csv_raw.decode('utf-8-sig')
    assert 'CI Supervisor' in csv_txt and 'John Moshi' not in csv_txt and 'Hours Worked' in csv_txt

    st, hdrs, pdf1 = get_bytes(f'/team/timesheet/{sup_id}?period=full')
    assert st == 200 and pdf1[:8] == b'%PDF-1.4', 'Invalid single worker PDF'
    pdf1_txt = pdf_streams(pdf1)
    assert b'CI Supervisor' in pdf1_txt and b'Workshop Main Gate' in pdf1_txt, 'Live worker/site missing from PDF!'

    st, hdrs, xlsx1 = get_bytes(f'/team/timesheet/{sup_id}?period=full&format=xlsx')
    assert st == 200 and xlsx1[:4] == b'PK\x03\x04', 'Invalid single worker XLSX'
    with zipfile.ZipFile(io.BytesIO(xlsx1)) as zf:
        assert 'xl/media/image1.png' in zf.namelist()
        assert 'xl/worksheets/sheet1.xml' in zf.namelist()
        assert 'xl/worksheets/sheet2.xml' in zf.namelist()

    st, _, batch_pdf = get_bytes(f'/team/timesheets_batch?period=h1&ids={sup_id}')
    assert st == 200 and batch_pdf[:8] == b'%PDF-1.4', 'Invalid multi-selected batch PDF'
    assert b'CI Supervisor' in pdf_streams(batch_pdf) and b'John Moshi' not in pdf_streams(batch_pdf)

    st, _, batch_xlsx = get_bytes('/team/timesheets_batch?period=h1&format=xlsx')
    assert st == 200 and batch_xlsx[:4] == b'PK\x03\x04', 'Invalid batch XLSX'

    def session_opener(email):
        fd, jar_file = tempfile.mkstemp(prefix='wms_jar_', suffix='.txt')
        login_p = subprocess.check_output(['curl', '-fsSL', '-c', jar_file, BASE + '/login']).decode('utf-8', 'replace')
        tok0 = csrf_from(login_p)
        subprocess.check_call([
            'curl', '-fsSL', '-b', jar_file, '-c', jar_file, BASE + '/login',
            '--data-urlencode', f'csrf_token_wms={tok0}',
            '--data-urlencode', f'email={email}',
            '--data-urlencode', 'password=AdminPass123!',
        ], stdout=subprocess.DEVNULL)
        mcj = http.cookiejar.MozillaCookieJar(jar_file)
        mcj.load(ignore_discard=True, ignore_expires=True)
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(mcj))

    # Manager (role_id = 4) read-only access succeeds across report, CSV, JSON drawer & PDF exports
    mgr_op = session_opener('mgr@ci.local')
    st_mgr_rep, _, mgr_rep_b = get_bytes('/team/report', mgr_op)
    st_mgr_js,  _, mgr_js_b  = get_bytes(f'/team/sheet_json/{sup_id}?period=h1', mgr_op)
    st_mgr_csv, _, mgr_csv_b = get_bytes('/team/report_csv', mgr_op)
    st_mgr1,    _, mgr_pdf   = get_bytes(f'/team/timesheet/{sup_id}?period=h1', mgr_op)
    st_mgr2,    _, mgr_batch = get_bytes('/team/timesheets_batch?period=h1', mgr_op)
    assert st_mgr_rep == 200 and b'All PDF Sheets' in mgr_rep_b
    assert st_mgr_js == 200 and json.loads(mgr_js_b.decode('utf-8')).get('ok') is True
    assert st_mgr_csv == 200 and mgr_csv_b.startswith(b'\xef\xbb\xbf')
    assert st_mgr1 == 200 and mgr_pdf[:8] == b'%PDF-1.4'
    assert st_mgr2 == 200 and mgr_batch[:8] == b'%PDF-1.4'

    # Technician (role_id = 2) Horizontal Privacy: sees ONLY own record on /team/report, /team/report_csv & /team/sheet_json
    tech_op = session_opener('tech1@ci.local')
    st_tech_rep, _, tech_rep_b = get_bytes('/team/report', tech_op)
    tech_rep_html = tech_rep_b.decode('utf-8', 'replace')
    assert st_tech_rep == 200 and 'My Attendance &amp; Timesheet' in tech_rep_html
    assert 'John Moshi' in tech_rep_html and 'CI Supervisor' not in tech_rep_html, 'Horizontal privacy leak on /team/report!'

    st_tech_csv, _, tech_csv_b = get_bytes('/team/report_csv', tech_op)
    tech_csv_txt = tech_csv_b.decode('utf-8-sig')
    assert st_tech_csv == 200 and 'John Moshi' in tech_csv_txt and 'CI Supervisor' not in tech_csv_txt, 'Horizontal privacy leak on CSV!'

    st_own_js, _, own_js_b = get_bytes(f'/team/sheet_json/{tech_id}?period=h1', tech_op)
    assert st_own_js == 200 and json.loads(own_js_b.decode('utf-8')).get('full_name') == 'John Moshi'
    st_forb_js, _, _ = get_bytes(f'/team/sheet_json/{sup_id}?period=h1', tech_op)
    assert st_forb_js == 403, f'Expected HTTP 403 for peer sheet_json, got {st_forb_js}'

    st_own, _, own_pdf = get_bytes(f'/team/timesheet/{tech_id}?period=h1', tech_op)
    assert st_own == 200 and own_pdf[:8] == b'%PDF-1.4' and b'John Moshi' in pdf_streams(own_pdf)
    st_other, hdr_other, other_body = get_bytes(f'/team/timesheet/{sup_id}?period=h1', tech_op)
    assert not other_body.startswith(b'%PDF-1.4'), 'Technician must NOT receive another user PDF!'

    # 8. Live Enterprise Workforce Directory (/team) E2E checks (Supervisor, Manager & Technician RBAC)
    sql_seed = f"""
    INSERT INTO certifications (user_id, cert_name, cert_number, issuing_body, issued_date, expiry_date, status)
    VALUES ({int(tech_id)}, 'Heavy Excavator Safety', 'CERT-EX-01', 'OSHA TZ', '2024-01-01', '2025-01-01', 'Valid');
    INSERT INTO work_orders (wo_number, equipment_id, title, type, priority, status, assigned_to, created_by)
    SELECT 'WO-CI-TEAM-01', id, 'Main boom cylinder seal leak', 'Corrective Maintenance', 'Critical', 'In Progress', '{int(tech_id)}', {int(sup_id)}
    FROM equipment WHERE registration_no = 'EX 006' LIMIT 1;
    INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, days_count, reason, status)
    VALUES ({int(tech_id)}, 'Annual Leave', '2026-11-10', '2026-11-12', 3, 'Family event', 'Pending');
    """
    subprocess.run(
        ['mysql', '-h', '127.0.0.1', '-uroot', f'-p{os.environ["WMS_DB_PASS"]}', 'wms_fresh'],
        input=sql_seed.encode('utf-8'),
        check=True,
    )

    team_html = get('/team')
    assert 'role="tablist"' in team_html and 'id="tmDrawer"' in team_html, 'Missing tabs/drawer on /team!'
    assert '1 Expired' in team_html, 'Stale certification status was NOT auto-refreshed on /team!'
    assert '1 Open WO' in team_html, 'Pending leave approval queue missed live open WO warning!'
    assert 'On Site' in team_html, 'Live geofenced QR On Site badge missing on /team!'

    # Verify server-side status tabs, search, sorting & out-of-range pagination clamping
    cert_tab_html = get('/team?tab=cert_alert')
    assert 'John Moshi' in cert_tab_html and 'data-member-name="CI Supervisor"' not in cert_tab_html
    onsite_tab_html = get('/team?tab=on_site')
    assert 'data-member-name="CI Supervisor"' in onsite_tab_html and 'data-member-name="John Moshi"' not in onsite_tab_html
    clamp_html = get('/team?sort=active_wos&dir=desc&per_page=10&page=999')
    assert 'aria-sort="descending"' in clamp_html and 'John Moshi' in clamp_html

    # Verify Staff Inspector Drawer JSON endpoint (/team/member_json/{tech_id})
    st_mj, _, mj_raw = get_bytes(f'/team/member_json/{tech_id}')
    mj_doc = json.loads(mj_raw.decode('utf-8'))
    assert st_mj == 200 and mj_doc.get('ok') is True and mj_doc.get('full_name') == 'John Moshi'
    assert any(w.get('wo_number') == 'WO-CI-TEAM-01' and w.get('machine') == 'EX 006' for w in mj_doc.get('open_workorders', []))
    assert any(c.get('cert_name') == 'Heavy Excavator Safety' and c.get('status') == 'Expired' for c in mj_doc.get('certifications', []))

    # Verify Workforce Roster CSV export (UTF-8 BOM + multi-select ?ids= filter)
    st_rcsv, _, rcsv_raw = get_bytes(f'/team/roster_csv?ids={tech_id}')
    assert st_rcsv == 200 and rcsv_raw.startswith(b'\xef\xbb\xbf'), '/team/roster_csv missing UTF-8 BOM!'
    rcsv_txt = rcsv_raw.decode('utf-8-sig')
    assert 'John Moshi' in rcsv_txt and 'CI Supervisor' not in rcsv_txt and 'Active Work Orders' in rcsv_txt

    # Manager (role_id = 4) read-only access succeeds on /team, /team/roster_csv & /team/member_json while hiding mutations
    st_mgr_tm, _, mgr_tm_b = get_bytes('/team', mgr_op)
    mgr_tm_html = mgr_tm_b.decode('utf-8', 'replace')
    assert st_mgr_tm == 200 and 'Export CSV' in mgr_tm_html and 'Add Worker' not in mgr_tm_html and 'Edit Worker Profile' not in mgr_tm_html
    st_mgr_rc, _, mgr_rc_b = get_bytes('/team/roster_csv', mgr_op)
    assert st_mgr_rc == 200 and mgr_rc_b.startswith(b'\xef\xbb\xbf')
    st_mgr_mj, _, mgr_mj_b = get_bytes(f'/team/member_json/{tech_id}', mgr_op)
    assert st_mgr_mj == 200 and json.loads(mgr_mj_b.decode('utf-8')).get('ok') is True

    # Technician (role_id = 2) Horizontal Privacy on /team, /team/view/{peer} & /team/member_json/{peer}
    st_tech_tm, _, tech_tm_b = get_bytes('/team', tech_op)
    tech_tm_html = tech_tm_b.decode('utf-8', 'replace')
    assert st_tech_tm == 200 and 'John Moshi' in tech_tm_html and 'mgr@ci.local' not in tech_tm_html
    st_peer_view, _, peer_view_b = get_bytes(f'/team/view/{sup_id}', tech_op)
    assert b'admin@ci.local' not in peer_view_b, 'Horizontal PII leak on /team/view/{peer_id}!'
    st_own_mj, _, own_mj_b = get_bytes(f'/team/member_json/{tech_id}', tech_op)
    assert st_own_mj == 200 and json.loads(own_mj_b.decode('utf-8')).get('full_name') == 'John Moshi'
    st_forb_mj, _, _ = get_bytes(f'/team/member_json/{sup_id}', tech_op)
    assert st_forb_mj == 403, f'Expected HTTP 403 for peer member_json, got {st_forb_mj}'

    # 9. Live Enterprise Work Orders DataTable (/workorders) E2E checks (Supervisor, Technician & Manager RBAC)
    sql_wo2 = f"""
    INSERT INTO work_orders (wo_number, equipment_id, title, type, priority, status, hold_code, expected_return_date, created_by)
    SELECT 'WO-CI-HOLD-02', id, 'Steer axle kingpin & seal kit', 'Preventive Maintenance', 'High', 'Waiting Parts', 'WSP', '2025-05-01', {int(sup_id)}
    FROM equipment WHERE registration_no = 'C-HEADT 007' LIMIT 1;
    INSERT INTO wo_spare_parts (work_order_id, item_code, part_name, quantity, unit)
    SELECT id, 'SP-KP-07', 'Hydraulic Boom Seal Kit', 2, 'pcs'
    FROM work_orders WHERE wo_number = 'WO-CI-HOLD-02' LIMIT 1;
    """
    subprocess.run(
        ['mysql', '-h', '127.0.0.1', '-uroot', f'-p{os.environ["WMS_DB_PASS"]}', 'wms_fresh'],
        input=sql_wo2.encode('utf-8'),
        check=True,
    )

    wo_html = get('/workorders')
    assert 'role="tablist"' in wo_html and 'id="woDrawer"' in wo_html, 'Missing workflow tabs/drawer on /workorders!'
    assert 'WO-CI-TEAM-01' in wo_html and 'WO-CI-HOLD-02' in wo_html
    assert 'WSP · Waiting for Spare Part' in wo_html and 'Overdue return' in wo_html

    m_wo1 = re.search(r'data-wo-row="(\d+)"[^>]*data-wo-num="WO-CI-TEAM-01"', wo_html)
    m_wo2 = re.search(r'data-wo-row="(\d+)"[^>]*data-wo-num="WO-CI-HOLD-02"', wo_html)
    assert m_wo1 and m_wo2, 'Expected both WO-CI-TEAM-01 and WO-CI-HOLD-02 rows'
    wo1_id, wo2_id = m_wo1.group(1), m_wo2.group(1)

    # Verify workflow status tabs, technician-name search, legacy ?status= link & page clamping
    hold_html = get('/workorders?tab=on_hold')
    assert 'WO-CI-HOLD-02' in hold_html and 'data-wo-num="WO-CI-TEAM-01"' not in hold_html
    tech_q_html = get('/workorders?q=Moshi')
    assert 'WO-CI-TEAM-01' in tech_q_html and 'data-wo-num="WO-CI-HOLD-02"' not in tech_q_html
    legacy_st_html = get('/workorders?status=In+Progress&sort=priority&dir=desc&per_page=10&page=999')
    assert 'WO-CI-TEAM-01' in legacy_st_html and 'aria-sort="descending"' in legacy_st_html

    # Verify JSON Drawer endpoint (/workorders/summary_json/{wo2_id})
    st_wj, _, wj_raw = get_bytes(f'/workorders/summary_json/{wo2_id}')
    wj_doc = json.loads(wj_raw.decode('utf-8'))
    assert st_wj == 200 and wj_doc.get('ok') is True and wj_doc.get('hold_label') == 'Waiting for Spare Part'
    assert any(p.get('part_name') == 'Hydraulic Boom Seal Kit' for p in wj_doc.get('spare_parts', []))

    # Technician (role_id = 2) can now export UTF-8 BOM CSV WITH unstarted Open/Hold WOs included!
    st_t_wcsv, _, t_wcsv_raw = get_bytes('/workorders/export_csv', tech_op)
    assert st_t_wcsv == 200 and t_wcsv_raw.startswith(b'\xef\xbb\xbf'), 'Technician blocked or missing BOM on /workorders/export_csv!'
    t_wcsv_txt = t_wcsv_raw.decode('utf-8-sig')
    assert 'WO-CI-TEAM-01' in t_wcsv_txt and 'WO-CI-HOLD-02' in t_wcsv_txt, 'Unstarted WO dropped from CSV export!'

    # Manager (role_id = 4) read-only access succeeds while hiding New WO & Edit mutations
    st_m_wo, _, m_wo_b = get_bytes('/workorders', mgr_op)
    m_wo_html = m_wo_b.decode('utf-8', 'replace')
    assert st_m_wo == 200 and 'Export CSV' in m_wo_html and 'New WO' not in m_wo_html and 'Edit Work Order' not in m_wo_html

    # Manager (role_id = 4) read-only access succeeds on BOTH /inspections AND /inspections/records/excavator!
    st_m_in, _, m_in_b = get_bytes('/inspections/records/excavator', mgr_op)
    m_in_html = m_in_b.decode('utf-8', 'replace')
    assert st_m_in == 200 and 'Export CSV' in m_in_html and 'EXCAVATOR' in m_in_html and 'id="inOpenLauncherBtn"' not in m_in_html

    # GET /inspections/save must reject non-POST requests with HTTP 405 Method Not Allowed
    try:
        tech_op.open(f'{BASE}/inspections/save')
        raise AssertionError('Expected HTTP 405 on GET /inspections/save')
    except urllib.error.HTTPError as e405:
        assert e405.code == 405, f'Expected 405 on GET /inspections/save, got {e405.code}'

    # Backlog F-08 Bypass Guard: Technician can raise a defect, CANNOT close via POST /backlog/edit/{id}!
    _, _, bl_new_b = get_bytes('/backlog/create', tech_op)
    bl_csrf = csrf_from(bl_new_b.decode('utf-8', 'replace'))
    bl_create_body = urllib.parse.urlencode({
        'csrf_token_wms': bl_csrf,
        'asset_label': 'EX 006',
        'start_date': '2026-10-20',
        'description': 'CI Technician Hydraulic Hose Chafing',
        'source': 'Inspection',
        'priority': 'P2',
        'status': 'Closed',  # Technician attempts forbidden self-closure!
    }).encode()
    bl_req = urllib.request.Request(f'{BASE}/backlog/create', data=bl_create_body, headers={'Content-Type': 'application/x-www-form-urlencoded'})
    with tech_op.open(bl_req) as bl_resp:
        bl_reject_html = bl_resp.read().decode('utf-8', 'replace')
    assert 'Closing a defect requires supervisor verification.' in bl_reject_html, 'Technician was allowed to self-close defect!'

    # Manager (role_id = 4) read-only access succeeds on /backlog, /backlog/export_csv (UTF-8 BOM), AND /backlog/export_xlsx (ZIP PK\x03\x04)
    st_m_bl, _, m_bl_b = get_bytes('/backlog', mgr_op)
    m_bl_html = m_bl_b.decode('utf-8', 'replace')
    assert st_m_bl == 200 and 'Export CSV' in m_bl_html and 'Download Excel' in m_bl_html and '+ New Defect' not in m_bl_html
    st_m_bl_csv, _, m_bl_csv = get_bytes('/backlog/export_csv', mgr_op)
    assert st_m_bl_csv == 200 and m_bl_csv.startswith(b'\xef\xbb\xbf')
    st_m_bl_xls, _, m_bl_xls = get_bytes('/backlog/export_xlsx', mgr_op)
    assert st_m_bl_xls == 200 and m_bl_xls[:4] == b'PK\x03\x04'

    # Inventory INV-01..INV-05 Live RBAC, Security & Export Governance Checks
    st_t_ping, _, _ = get_bytes('/materials/ping', tech_op)
    assert st_t_ping == 403, f'Expected HTTP 403 on non-supervisor /materials/ping, got {st_t_ping}'
    st_s_ping, _, s_ping_b = get_bytes('/materials/ping', opener)
    assert st_s_ping == 200 and b'PHP:' not in s_ping_b, 'Supervisor /materials/ping failed or leaked PHP version!'

    store_op = session_opener('store@ci.local')
    st_st_dash, _, st_dash_b = get_bytes('/materials', store_op)
    st_dash_html = st_dash_b.decode('utf-8', 'replace')
    assert st_st_dash == 200 and 'materials/create' in st_dash_html, 'Store role locked out on /materials dashboard!'
    st_st_inv, _, st_inv_b = get_bytes('/materials/inventory', store_op)
    st_inv_html = st_inv_b.decode('utf-8', 'replace')
    assert (
        st_st_inv == 200
        and 'id="invAddManualBtn"' in st_inv_html
        and 'id="invImportToggleBtn"' in st_inv_html
        and 'data-inv-move="receive"' in st_inv_html
    ), 'Store role (role_id=3) still locked out of inventory actions!'

    st_m_inv, _, m_inv_b = get_bytes('/materials/inventory', mgr_op)
    m_inv_html = m_inv_b.decode('utf-8', 'replace')
    assert (
        st_m_inv == 200
        and 'Export CSV' in m_inv_html
        and 'Export Excel' in m_inv_html
        and 'id="invAddManualBtn"' not in m_inv_html
        and 'data-inv-move="receive"' not in m_inv_html
    ), 'Manager role RBAC mismatch on /materials/inventory!'
    st_m_icsv, _, m_icsv_b = get_bytes('/materials/export_csv?stock=out', mgr_op)
    assert st_m_icsv == 200 and m_icsv_b.startswith(b'\xef\xbb\xbf') and b'Qty To Order' in m_icsv_b
    st_m_ixls, _, m_ixls_b = get_bytes('/materials/export_xlsx?stock=out', mgr_op)
    assert st_m_ixls == 200 and m_ixls_b[:4] == b'PK\x03\x04'

    # Stock Transactions Ledger TXN-01..TXN-05 Live RBAC & Binary CSV/XLSX Verification
    st_m_txn, _, m_txn_b = get_bytes('/materials/transactions?tab=equipment&date_from=2026-02-01&date_to=2026-02-28', mgr_op)
    m_txn_html = m_txn_b.decode('utf-8', 'replace')
    assert (
        st_m_txn == 200
        and 'Stock Transaction Ledger' in m_txn_html
        and 'Export CSV' in m_txn_html
        and 'Export Excel' in m_txn_html
        and 'Feb 2026' in m_txn_html
        and '06 Oct 2026' not in m_txn_html
    ), 'Transactions ledger failed or leaked migration timestamp 06 Oct 2026 on Feb 2026 window!'
    st_m_tcsv, _, m_tcsv_b = get_bytes('/materials/transactions_csv?tab=equipment&date_from=2026-09-01', mgr_op)
    assert st_m_tcsv == 200 and m_tcsv_b.startswith(b'\xef\xbb\xbf') and b'Reference / MRN / R.non' in m_tcsv_b
    st_m_txls, _, m_txls_b = get_bytes('/materials/transactions_xlsx?tab=valued&date_from=2026-09-01', mgr_op)
    assert st_m_txls == 200 and m_txls_b[:4] == b'PK\x03\x04'

    # Spare Orders ORD-01..ORD-06 End-to-End Multi-Currency, Partial GRN, RBAC & Export Verification
    def post_op(op, path, fields):
        data = urllib.parse.urlencode(fields, doseq=True).encode()
        req = urllib.request.Request(f'{BASE}{path}', data=data, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        try:
            r = op.open(req)
            return r.status, r.geturl(), r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, path, e.read().decode('utf-8', 'replace')

    # Create active supplier as Storekeeper (role_id = 3)
    _, _, sup_new_b = get_bytes('/suppliers/create', store_op)
    st_sup_c, sup_url, _ = post_op(store_op, '/suppliers/create', {
        'csrf_token_wms': csrf_from(sup_new_b.decode('utf-8', 'replace')),
        'company_name': 'Mantrac CAT Intl',
        'country': 'Germany',
        'currency': 'USD',
        'status': 'Active',
    })
    assert st_sup_c == 200 and '/suppliers/view/' in sup_url, f'Supplier create failed: {st_sup_c} {sup_url}'
    sup_id_new = int(sup_url.rstrip('/').split('/')[-1])

    # PO #1: USD order linked to wo1_id ('EX 006'), walked to In Transit, partial GRN receipt (1/4) with Shortage
    _, _, po1_new_b = get_bytes('/orders/create', store_op)
    st_po1, po1_url, _ = post_op(store_op, '/orders/create', {
        'csrf_token_wms': csrf_from(po1_new_b.decode('utf-8', 'replace')),
        'supplier_id': str(sup_id_new),
        'shipping_method': 'Air Freight',
        'order_date': '2026-10-01',
        'expected_delivery_date': '2026-10-25',
        'currency': 'USD',
        'total_cost': '1450.00',
        'linked_wo_id': str(wo1_id),
        'notes': '=CMD("urgent boom seal kit")',
        'item_description[]': ['Main Boom Seal Kit'],
        'item_part_number[]': ['295-5609'],
        'item_unit[]': ['Set'],
        'item_qty[]': ['4'],
        'item_price[]': ['362.50'],
    })
    assert st_po1 == 200 and '/orders/view/' in po1_url, f'PO1 create failed: {st_po1} {po1_url}'
    po1_id = int(po1_url.rstrip('/').split('/')[-1])

    # Manager (role_id = 4) approves Pending Approval -> Sent to Supplier per MANAGER_WRITE_WHITELIST,
    # then Storekeeper advances Confirmed -> In Transit
    _, _, mgr_pv_b = get_bytes(f'/orders/view/{po1_id}', mgr_op)
    st_mgr_app, _, _ = post_op(mgr_op, f'/orders/update_status/{po1_id}', {
        'csrf_token_wms': csrf_from(mgr_pv_b.decode('utf-8', 'replace')),
        'status': 'Sent to Supplier',
        'notes': 'Approved by Manager',
    })
    assert st_mgr_app == 200, f'Manager PO approval failed: {st_mgr_app}'

    for next_st in ('Confirmed', 'In Transit'):
        _, _, pv_b = get_bytes(f'/orders/view/{po1_id}', store_op)
        st_up, _, _ = post_op(store_op, f'/orders/update_status/{po1_id}', {
            'csrf_token_wms': csrf_from(pv_b.decode('utf-8', 'replace')),
            'status': next_st,
            'tracking_number': 'DHL-8841209',
            'carrier': 'DHL Express',
            'notes': f'Moved to {next_st}',
        })
        assert st_up == 200

    # Partial GRN receipt (1 of 4 units) with Shortage discrepancy
    _, _, rcv_b = get_bytes(f'/orders/receive/{po1_id}', store_op)
    rcv_html = rcv_b.decode('utf-8', 'replace')
    sub_tok = re.search(r'name=["\x27]submit_token["\x27]\s+value=["\x27]([a-f0-9]{32})["\x27]', rcv_html)
    itm_m = re.search(r'name=["\x27]qty_delivery\[(\d+)\]["\x27]', rcv_html)
    assert sub_tok and itm_m, 'Missing submit_token or qty_delivery input on /orders/receive!'
    st_rcv, _, _ = post_op(store_op, f'/orders/receive/{po1_id}', {
        'csrf_token_wms': csrf_from(rcv_html),
        'submit_token': sub_tok.group(1),
        f'qty_delivery[{itm_m.group(1)}]': '1',
        'discrepancy_flag': 'Shortage',
        'discrepancy_notes': '3 seal kits backordered',
    })
    assert st_rcv == 200

    # PO #2: TZS overdue order linked to wo2_id ('C-HEADT 007') in Transit
    _, _, po2_new_b = get_bytes('/orders/create', store_op)
    st_po2, po2_url, _ = post_op(store_op, '/orders/create', {
        'csrf_token_wms': csrf_from(po2_new_b.decode('utf-8', 'replace')),
        'supplier_id': str(sup_id_new),
        'shipping_method': 'Sea Freight',
        'order_date': '2026-10-02',
        'expected_delivery_date': '2026-10-10',
        'currency': 'TZS',
        'total_cost': '3800000.00',
        'linked_wo_id': str(wo2_id),
        'notes': 'Port Dar shipment',
        'item_description[]': ['Steer Axle Kingpin Kit'],
        'item_part_number[]': ['KP-3000'],
        'item_unit[]': ['pcs'],
        'item_qty[]': ['2'],
        'item_price[]': ['1900000.00'],
    })
    assert st_po2 == 200 and '/orders/view/' in po2_url
    po2_id = int(po2_url.rstrip('/').split('/')[-1])
    for next_st in ('Sent to Supplier', 'Confirmed', 'In Transit'):
        _, _, pv2_b = get_bytes(f'/orders/view/{po2_id}', store_op)
        st_up2, _, _ = post_op(store_op, f'/orders/update_status/{po2_id}', {
            'csrf_token_wms': csrf_from(pv2_b.decode('utf-8', 'replace')),
            'status': next_st,
            'tracking_number': 'MAERSK-55201',
            'carrier': 'Maersk Line',
        })
        assert st_up2 == 200

    # Part-number search + Country & Shipping Method facet filters work cleanly
    st_pq, _, pq_b = get_bytes('/orders?q=295-5609&country=Germany&shipping_method=Air+Freight', store_op)
    pq_html = pq_b.decode('utf-8', 'replace')
    assert st_pq == 200 and 'DHL-8841209' in pq_html and 'MAERSK-55201' not in pq_html and '25%' in pq_html

    # Manager (role_id = 4) RBAC: full read & multi-currency visibility, ZERO mutation buttons, HTTP 403 on write endpoints
    st_m_ord, _, m_ord_b = get_bytes('/orders', mgr_op)
    m_ord_html = m_ord_b.decode('utf-8', 'replace')
    assert (
        st_m_ord == 200
        and 'USD 1,450.00' in m_ord_html
        and 'TZS 3,800,000.00' in m_ord_html
        and '3,801,450' not in m_ord_html
        and 'EX 006' in m_ord_html
        and 'C-HEADT 007' in m_ord_html
        and 'id="ordNewBtn"' not in m_ord_html
    ), 'Manager /orders multi-currency or RBAC assertion failed!'

    st_m_ovw, _, m_ovw_b = get_bytes(f'/orders/view/{po1_id}', mgr_op)
    m_ovw_html = m_ovw_b.decode('utf-8', 'replace')
    assert (
        st_m_ovw == 200
        and 'DHL-8841209' in m_ovw_html
        and 'Update Status' in m_ovw_html
        and 'Receive Items at Store' not in m_ovw_html
        and 'Receive Outstanding Balance' not in m_ovw_html
        and 'orders/upload_document/' not in m_ovw_html
    ), 'Manager /orders/view RBAC mismatch!'

    r_forb1 = mgr_op.open(f'{BASE}/orders/create')
    forb1_url, forb1_html = r_forb1.geturl(), r_forb1.read().decode('utf-8', 'replace')
    r_forb2 = mgr_op.open(f'{BASE}/orders/receive/{po1_id}')
    forb2_url, forb2_html = r_forb2.geturl(), r_forb2.read().decode('utf-8', 'replace')
    assert (
        '/dashboard' in forb1_url and 'view-only access' in forb1_html
        and '/dashboard' in forb2_url and 'view-only access' in forb2_html
    ), f'Expected Manager redirect to /dashboard with view-only error, got {forb1_url}, {forb2_url}'

    # Slide-Over Drawer JSON + CSV (UTF-8 BOM + formula guard) + Styled XLSX (PK\x03\x04)
    st_m_oj, _, m_oj_b = get_bytes(f'/orders/summary_json/{po1_id}', mgr_op)
    m_oj = json.loads(m_oj_b.decode('utf-8'))
    assert (
        st_m_oj == 200
        and m_oj.get('ok') is True
        and m_oj['order']['can_write'] is False
        and m_oj['order']['can_receive'] is False
        and m_oj['order']['linked_wo']['registration_no'] == 'EX 006'
        and len(m_oj['receipts']) == 1
    )
    st_m_ocsv, _, m_ocsv_b = get_bytes('/orders/export_csv', mgr_op)
    m_ocsv_txt = m_ocsv_b.decode('utf-8-sig')
    assert (
        st_m_ocsv == 200
        and m_ocsv_b.startswith(b'\xef\xbb\xbf')
        and "'=CMD(" in m_ocsv_txt
        and 'EX 006' in m_ocsv_txt
        and 'C-HEADT 007' in m_ocsv_txt
    )
    st_m_oxls, _, m_oxls_b = get_bytes('/orders/export_xlsx', mgr_op)
    assert st_m_oxls == 200 and m_oxls_b[:4] == b'PK\x03\x04'

    print('ALL LIVE ATTENDANCE WORKFLOW, WORKFORCE DIRECTORY, WORK ORDERS, INSPECTIONS, BACKLOG, INVENTORY & SPARE ORDERS E2E CHECKS PASSED!')
except BaseException:
    tb = traceback.format_exc().replace('\n', '%0A')
    print(f'::error title=PythonE2E::{tb}', flush=True)
    raise
