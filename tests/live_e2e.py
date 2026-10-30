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

    # Exercise the real database + authenticated defect paths, not just fakes.
    # Each new form has a server-generated idempotency token; refresh only the
    # CSRF value when replaying that same submission.
    def bl_sql(sql):
        return subprocess.check_output([
            'mysql', '-h', '127.0.0.1', '-uroot', f'-p{os.environ["WMS_DB_PASS"]}',
            '--batch', '--skip-column-names', os.environ.get('WMS_DB_NAME', 'wms_fresh'),
        ], input=sql.encode('utf-8')).decode('utf-8').strip()

    def bl_form(op):
        html = get('/backlog/create', op)
        token_match = re.search(r'name=["\x27]submit_token["\x27]\s+value=["\x27]([a-f0-9]{32})', html)
        assert token_match, 'Missing defect idempotency token'
        return csrf_from(html), token_match.group(1)

    def bl_submit(op, fields):
        req = urllib.request.Request(BASE + '/backlog/create',
            data=urllib.parse.urlencode(fields).encode('utf-8'),
            headers={'Content-Type': 'application/x-www-form-urlencoded'})
        with op.open(req) as response:
            return response.read().decode('utf-8', 'replace')

    base_defect = {'asset_label':'EX 006', 'start_date':'2020-01-01',
                   'source':'Other', 'priority':'P2', 'status':'Open'}
    csrf, token = bl_form(tech_op)
    tech_defect = dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI invalid plan still saves defect', requested_date='2041-06-06',
        requested_duration='0', plan_action='schedule')
    tech_result = bl_submit(tech_op, tech_defect)
    assert 'saved' in tech_result.lower(), 'Invalid planning input blocked the defect'
    row = bl_sql("SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(schedule_state),''), ':', "
        "COALESCE(MAX(requested_duration),-1)) FROM backlogs WHERE submit_token='" + token + "'")
    assert row == '1:needs_info:-1', f'Invalid plan was treated as scheduled: {row}'
    assert bl_sql("SELECT COUNT(*) FROM maintenance_plan_jobs j JOIN backlogs b ON b.id=j.backlog_id WHERE b.submit_token='" + token + "'") == '0'
    tech_defect['csrf_token_wms'], _ = bl_form(tech_op)
    bl_submit(tech_op, tech_defect)
    assert bl_sql("SELECT COUNT(*) FROM backlogs WHERE submit_token='" + token + "'") == '1', 'Retry duplicated a defect'
    defect_id = bl_sql("SELECT id FROM backlogs WHERE submit_token='" + token + "'")
    exact_register = get('/backlog?q=BL-' + defect_id, tech_op)
    assert ('data-bl-open="' + defect_id + '"') in exact_register, 'Similar-defect link did not locate the existing record'

    csrf, token = bl_form(opener)
    hist_result = bl_submit(opener, dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI historical defect evidence', status='Closed', historical='1',
        completion_source='CI job card', closing_remarks='Hose replaced'))
    assert 'saved' in hist_result.lower(), 'Historical defect was not saved'
    historical = bl_sql("SELECT CONCAT(id, ':', closure_kind, ':', end_date IS NULL, ':', "
        "verified_by IS NULL, ':', reviewed_by IS NOT NULL) FROM backlogs WHERE submit_token='" + token + "'")
    parts = historical.split(':')
    assert len(parts) == 5 and parts[1:] == ['historical','1','1','1'], f'Invented historical evidence: {historical}'
    detail = json.loads(get('/backlog/summary_json/' + parts[0], opener))['item']
    assert detail['verification_label'] == 'Historical completion; verification not recorded' and detail['days_down'] is None

    # Edits from this form carry a version. A concurrent change after the
    # form was opened must be surfaced instead of overwritten.
    csrf, token = bl_form(opener)
    bl_submit(opener, dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI stale edit fixture'))
    stale_id = bl_sql("SELECT id FROM backlogs WHERE submit_token='" + token + "'")
    edit_html = get('/backlog/edit/' + stale_id, opener)
    version_match = re.search(r'name=["\x27]record_version["\x27]\s+value=["\x27]([a-f0-9]{64})', edit_html)
    assert version_match, 'Missing edit conflict token'
    bl_sql("UPDATE backlogs SET observation='Concurrent inspection note' WHERE id=" + stale_id)
    stale_fields = dict(base_defect, csrf_token_wms=csrf_from(edit_html), record_version=version_match.group(1),
        description='Stale browser edit')
    stale_req = urllib.request.Request(BASE + '/backlog/edit/' + stale_id,
        data=urllib.parse.urlencode(stale_fields).encode('utf-8'), headers={'Content-Type':'application/x-www-form-urlencoded'})
    with opener.open(stale_req) as response:
        stale_html = response.read().decode('utf-8', 'replace')
    assert 'changed while you were editing' in stale_html
    assert bl_sql('SELECT observation FROM backlogs WHERE id=' + stale_id) == 'Concurrent inspection note'
    assert bl_sql('SELECT description FROM backlogs WHERE id=' + stale_id) == 'CI stale edit fixture'

    # Cancellation remains a distinct reasoned outcome in the detail API and
    # both exports; it must not be presented as a verified completion.
    csrf, token = bl_form(opener)
    cancelled_result = bl_submit(opener, dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI cancelled duplicate', status='Cancelled', cancellation_reason='CI duplicate report'))
    assert 'saved' in cancelled_result.lower(), 'Reasoned cancellation was not saved'
    cancelled_id = bl_sql("SELECT id FROM backlogs WHERE submit_token='" + token + "'")
    cancelled = json.loads(get('/backlog/summary_json/' + cancelled_id, opener))['item']
    assert cancelled['status'] == 'Cancelled' and cancelled['cancellation_reason'] == 'CI duplicate report' and not cancelled['verification_label']
    assert cancelled['reviewer_name'] != '—' and cancelled['reviewed_at'] != '—'
    csv = get('/backlog/export_csv', opener)
    assert 'CI duplicate report' in csv and 'Reviewed By' in csv
    _, _, cancelled_xlsx = get_bytes('/backlog/export_xlsx', opener)
    with zipfile.ZipFile(io.BytesIO(cancelled_xlsx)) as book:
        xml = ''.join(book.read(name).decode('utf-8') for name in book.namelist() if name.endswith('.xml'))
    assert 'CANCELLATION REASON' in xml and 'CI duplicate report' in xml

    # Freeze an isolated future week. The defect must commit while its failed
    # plan remains absent and is labelled failed, not silently accepted.
    bl_sql("INSERT INTO forecast_snapshots (week_start,week_end,iso_label,revision,is_baseline,status,payload,payload_checksum) "
        "VALUES ('2040-06-04','2040-06-10','2040-W23',1,1,'Published','{}',SHA2('{}',256))")
    csrf, token = bl_form(opener)
    frozen_result = bl_submit(opener, dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI frozen plan saves defect', requested_date='2040-06-06', requested_duration='60', plan_action='schedule'))
    assert 'plan was not changed' in frozen_result.lower(), 'Frozen-plan failure was not explained'
    assert bl_sql("SELECT schedule_state FROM backlogs WHERE submit_token='" + token + "'") == 'failed'
    assert bl_sql("SELECT COUNT(*) FROM maintenance_plan_jobs j JOIN backlogs b ON b.id=j.backlog_id WHERE b.submit_token='" + token + "'") == '0'

    # A different draft week can commit a real job and forecast ledger row.
    csrf, token = bl_form(opener)
    plan_result = bl_submit(opener, dict(base_defect, csrf_token_wms=csrf, submit_token=token,
        description='CI committed plan job', requested_date='2041-06-06', requested_duration='60', plan_action='schedule'))
    assert 'job saved' in plan_result.lower(), 'Valid supervisor plan was not committed'
    assert bl_sql("SELECT COUNT(*) FROM maintenance_plan_jobs j JOIN backlogs b ON b.id=j.backlog_id "
        "WHERE b.submit_token='" + token + "' AND j.status='Planned' AND j.source='FromBacklog'") == '1'
    assert bl_sql("SELECT COUNT(*) FROM equipment_planned_downtime d JOIN backlogs b ON b.equipment_id=d.equipment_id "
        "WHERE b.submit_token='" + token + "' AND d.plan_date='2041-06-06' AND d.category='Corrective' AND d.source='FromPlan'") == '1'

    # Editing a linked job through Forecast cannot silently move it to another
    # machine, even though that editor may change an ordinary unlinked job.
    linked_job = bl_sql("SELECT j.id FROM maintenance_plan_jobs j JOIN backlogs b ON b.id=j.backlog_id WHERE b.submit_token='" + token + "'")
    original_machine = bl_sql("SELECT equipment_id FROM maintenance_plan_jobs WHERE id=" + linked_job)
    other_machine = bl_sql("SELECT id FROM equipment WHERE id<>" + original_machine + " ORDER BY id LIMIT 1")
    job_page = get('/forecast/jobs?week=2041-W23&equipment_id=' + original_machine + '&date=2041-06-06&edit=' + linked_job, opener)
    _, wrong_machine = post('/forecast/save_job?week=2041-W23', {
        'csrf_token_wms': csrf_from(job_page), 'id': linked_job, 'equipment_id': other_machine,
        'start_date': '2041-06-06', 'end_date': '2041-06-06', 'hours_per_day': '1',
        'job_type': 'Repair', 'category': 'Corrective', 'title': 'CI linked job edit', 'status': 'Planned'})
    assert 'linked defect job must use' in wrong_machine.lower()
    assert bl_sql("SELECT equipment_id FROM maintenance_plan_jobs WHERE id=" + linked_job) == original_machine

    # The HTTP forecast routes use the same locked transaction as backlog
    # planning: a published manual cell stays frozen against later edits,
    # generation and job creation.
    fc_week, fc_date = '2081-W20', '2081-05-12'
    fc_id = bl_sql("SELECT id FROM equipment WHERE registration_no='EX 006' LIMIT 1")
    fc_page = get('/forecast?week=' + fc_week, opener)
    _, cell_saved = post('/forecast/save_cell?week=' + fc_week, {
        'csrf_token_wms': csrf_from(fc_page), 'equipment_id': fc_id,
        'plan_date': fc_date, 'category': 'Corrective', 'hours': '3'})
    assert 'Planned Corrective hours updated' in cell_saved
    assert bl_sql("SELECT CONCAT(hours,':',source) FROM equipment_planned_downtime WHERE equipment_id="
        + fc_id + " AND plan_date='" + fc_date + "' AND category='Corrective'") == '3.00:Manual'
    fc_page = get('/forecast?week=' + fc_week, opener)
    _, published = post('/forecast/publish?week=' + fc_week, {
        'csrf_token_wms': csrf_from(fc_page), 'revision_note': 'CI acknowledgement'})
    assert 'published' in published.lower()
    assert bl_sql("SELECT COUNT(*) FROM forecast_snapshots WHERE week_start='" + fc_date + "' AND is_baseline=1") == '1'
    fc_page = get('/forecast?week=' + fc_week, opener)
    _, frozen_cell = post('/forecast/save_cell?week=' + fc_week, {
        'csrf_token_wms': csrf_from(fc_page), 'equipment_id': fc_id,
        'plan_date': fc_date, 'category': 'Corrective', 'hours': '9'})
    assert 'published and frozen' in frozen_cell.lower()
    assert bl_sql("SELECT hours FROM equipment_planned_downtime WHERE equipment_id="
        + fc_id + " AND plan_date='" + fc_date + "' AND category='Corrective'") == '3.00'
    fc_page = get('/forecast?week=' + fc_week, opener)
    _, frozen_generation = post('/forecast/generate?week=' + fc_week, {
        'csrf_token_wms': csrf_from(fc_page), 'what': 'standing'})
    assert 'published and frozen' in frozen_generation.lower()
    fc_page = get('/forecast/jobs?week=' + fc_week + '&equipment_id=' + fc_id + '&date=' + fc_date, opener)
    _, frozen_job = post('/forecast/save_job?week=' + fc_week, {
        'csrf_token_wms': csrf_from(fc_page), 'equipment_id': fc_id,
        'start_date': fc_date, 'end_date': fc_date, 'hours_per_day': '1',
        'job_type': 'Repair', 'category': 'Corrective', 'title': 'CI HTTP frozen job', 'status': 'Planned'})
    assert 'published and frozen' in frozen_job.lower()
    assert bl_sql("SELECT COUNT(*) FROM maintenance_plan_jobs WHERE title='CI HTTP frozen job'") == '0'

    # Work-order verification is a separate supervisor action. Selecting one
    # linked defect must not silently close the other; a later session start
    # must not reopen a closed WO, even if its start time was never recorded.
    bl_sql(f"""
        SET @eq = (SELECT id FROM equipment WHERE registration_no='EX 006' LIMIT 1);
        INSERT INTO work_orders (wo_number,equipment_id,title,type,priority,status,action_taken,end_datetime,created_by,completed_by)
        VALUES ('WO-CI-VERIFY-03',@eq,'Verify linked defects','Corrective Maintenance','Medium','Completed','Hose replaced','2020-02-01 09:00:00',{int(sup_id)},{int(sup_id)});
        SET @wo = LAST_INSERT_ID();
        INSERT INTO backlogs (equipment_id,asset_label,start_date,description,source,status,work_order_id)
        VALUES (@eq,'EX 006','2020-01-01','CI resolved defect','Other','Open',@wo),
               (@eq,'EX 006','2020-01-01','CI unresolved defect','Other','Open',@wo);
    """)
    verify_wo = bl_sql("SELECT id FROM work_orders WHERE wo_number='WO-CI-VERIFY-03'")
    resolved = bl_sql("SELECT id FROM backlogs WHERE description='CI resolved defect'")
    unresolved = bl_sql("SELECT id FROM backlogs WHERE description='CI unresolved defect'")

    def wo_post(op, path, fields):
        req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(fields, doseq=True).encode('utf-8'),
            headers={'Content-Type':'application/x-www-form-urlencoded'})
        with op.open(req) as response:
            return response.read().decode('utf-8', 'replace')

    tech_csrf = csrf_from(get('/backlog/create', tech_op))
    denied = wo_post(tech_op, '/workorders/update_status/' + verify_wo,
        {'csrf_token_wms':tech_csrf, 'status':'Closed', 'verification_notes':'Checked'})
    assert 'supervisor' in denied.lower() and bl_sql('SELECT status FROM work_orders WHERE id=' + verify_wo) == 'Completed'
    supervisor_form = get('/workorders/view/' + verify_wo, opener)
    assert 'resolved_defect_ids[]' in supervisor_form and 'wo-verification-notes' in supervisor_form
    verified = wo_post(opener, '/workorders/update_status/' + verify_wo,
        {'csrf_token_wms':csrf_from(supervisor_form), 'status':'Closed',
         'verification_notes':'Pressure tested', 'resolved_defect_ids[]':[resolved]})
    assert 'work order verified' in verified.lower()
    assert bl_sql('SELECT status FROM backlogs WHERE id=' + resolved) == 'Closed'
    assert bl_sql('SELECT status FROM backlogs WHERE id=' + unresolved) == 'Open'
    tech_csrf = csrf_from(get('/backlog/create', tech_op))
    denied_session = wo_post(tech_op, '/workorders/start_session/' + verify_wo,
        {'csrf_token_wms':tech_csrf})
    assert 'cannot start' in denied_session.lower()
    assert bl_sql('SELECT status FROM work_orders WHERE id=' + verify_wo) == 'Closed'
    # A separate completed fixture lets the browser suite exercise the real
    # verification form without submitting or changing the first scenario.
    bl_sql(f"""
        SET @eq = (SELECT id FROM equipment WHERE registration_no='EX 006' LIMIT 1);
        INSERT INTO work_orders (wo_number,equipment_id,title,type,priority,status,action_taken,end_datetime,created_by,completed_by)
        VALUES ('WO-CI-Z-UI-04',@eq,'UI verification fixture','Corrective Maintenance','Medium','Completed','Pump repaired','2020-02-01 09:00:00',{int(sup_id)},{int(sup_id)});
        SET @wo = LAST_INSERT_ID();
        INSERT INTO backlogs (equipment_id,asset_label,start_date,description,source,status,work_order_id)
        VALUES (@eq,'EX 006','2020-01-01','CI UI defect one','Other','Open',@wo),
               (@eq,'EX 006','2020-01-01','CI UI defect two','Other','Open',@wo);
    """)

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
        'notes': '=SUM(1,2)',
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
        and "'=SUM(1,2)" in m_ocsv_txt
        and 'EX 006' in m_ocsv_txt
        and 'C-HEADT 007' in m_ocsv_txt
    ), f'CSV check failed: status={st_m_ocsv} body={m_ocsv_txt[:400]!r}'
    st_m_oxls, _, m_oxls_b = get_bytes('/orders/export_xlsx', mgr_op)
    assert st_m_oxls == 200 and m_oxls_b[:4] == b'PK\x03\x04'

    # Pending Spares SP-01..SP-06 Live Alert Isolation, Allocation, RBAC & Export Verification
    _, _, mat_new_b = get_bytes('/materials/create', store_op)
    st_mc, _, _ = post_op(store_op, '/materials/create', {
        'csrf_token_wms': csrf_from(mat_new_b.decode('utf-8', 'replace')),
        'item_code': 'SP-EX-006',
        'category': 'SPARE PART',
        'description': 'Main Boom Seal Kit',
        'part_number_size': '295-5609',
        'unit': 'Set',
        'initial_stock': '5',
        'min_stock': '2',
        'location': 'BIN C-03',
    })
    assert st_mc == 200

    sql_sp = f"""
    INSERT INTO wo_spare_parts (work_order_id, item_code, part_name, part_number, quantity, unit)
    VALUES ({int(wo1_id)}, 'SP-EX-006', '=SUM(3,4)', '295-5609', 2, 'Set');
    INSERT INTO spare_part_alerts (wo_spare_part_id, work_order_id, material_id, item_code, part_name, qty_received, stock_after, alert_type, message, received_by, status, created_at)
    SELECT sp.id, {int(wo2_id)}, m.id, 'SP-EX-006', 'Kingpin Seal Kit', 2, 5, 'Available',
           'Stock arrived for WO-CI-HOLD-02', {int(sup_id)}, 'New', NOW()
    FROM wo_spare_parts sp
    CROSS JOIN materials m
    WHERE sp.work_order_id = {int(wo2_id)} AND m.item_code = 'SP-EX-006'
    LIMIT 1;
    """
    subprocess.run(
        ['mysql', '-h', '127.0.0.1', '-uroot', f'-p{os.environ["WMS_DB_PASS"]}', 'wms_fresh'],
        input=sql_sp.encode('utf-8'),
        check=True,
    )

    # Global Find + Operational Attention: exact identifiers, role scope and direct destinations.
    def search_doc(path, op):
        st_search, _, raw_search = get_bytes(path, op)
        assert st_search == 200, f'Global Find returned HTTP {st_search}: {raw_search[:240]!r}'
        doc = json.loads(raw_search.decode('utf-8'))
        assert doc.get('ok') is True, doc
        return doc

    def find_results(doc, record_type):
        return [
            item for group in doc.get('groups', []) for item in group.get('results', [])
            if item.get('type') == record_type
        ]

    sup_find = search_doc('/search?q=WO-CI-TEAM-01', opener)
    sup_wo_results = find_results(sup_find, 'work_order')
    assert len(sup_wo_results) == 1 and sup_wo_results[0]['identifier'] == 'WO-CI-TEAM-01'
    assert sup_wo_results[0]['url'].endswith(f'/workorders/view/{wo1_id}')
    assert sup_wo_results[0]['status'] == 'In Progress' and 'EX 006' in sup_wo_results[0]['context']

    eq_find = search_doc('/search?q=' + urllib.parse.quote('EX 006'), mgr_op)
    assert any(item['identifier'] == 'EX 006' for item in find_results(eq_find, 'equipment'))

    tech_find = search_doc('/search?q=WO-CI-TEAM-01', tech_op)
    assert any(item['identifier'] == 'WO-CI-TEAM-01' for item in find_results(tech_find, 'work_order'))
    tech_peer_find = search_doc('/search?q=WO-CI-HOLD-02', tech_op)
    assert not find_results(tech_peer_find, 'work_order'), 'Technician found an unrelated WO through Global Find!'

    store_find = search_doc('/search?q=WO-CI-TEAM-01', store_op)
    assert not find_results(store_find, 'work_order') and not find_results(store_find, 'equipment')
    store_part_find = search_doc('/search?q=295-5609', store_op)
    store_items = find_results(store_part_find, 'inventory')
    assert len(store_items) == 1 and store_items[0]['identifier'] == 'SP-EX-006'
    assert store_items[0]['status'] == 'In stock' and '295-5609' in store_items[0]['context']

    # The inventory destination filters to the exact SKU and exposes its existing drawer opener.
    item_url = urllib.parse.urlsplit(store_items[0]['url'])
    item_id = urllib.parse.parse_qs(item_url.query).get('item', [''])[0]
    assert item_id and urllib.parse.parse_qs(item_url.query).get('q') == ['SP-EX-006']
    item_path = item_url.path + '?' + item_url.query
    st_item_link, _, item_link_b = get_bytes(item_path, store_op)
    assert st_item_link == 200 and f'data-inv-open="{item_id}"' in item_link_b.decode('utf-8', 'replace')

    # New parts appear for authorized roles, but not in an unrelated technician's attention.
    _, _, mgr_attention_b = get_bytes('/spareparts/alerts', mgr_op)
    mgr_attention_html = mgr_attention_b.decode('utf-8', 'replace')
    mgr_topbar = mgr_attention_html.split('<header class="topbar">', 1)[1].split('</header>', 1)[0]
    assert 'Parts newly available' in mgr_topbar and 'Kingpin Seal Kit' in mgr_topbar
    assert f'workorders/view/{wo2_id}' in mgr_topbar and 'spareparts/alerts?status=New' in mgr_topbar

    _, _, tech_attention_b = get_bytes('/spareparts', tech_op)
    tech_attention_html = tech_attention_b.decode('utf-8', 'replace')
    tech_topbar = tech_attention_html.split('<header class="topbar">', 1)[1].split('</header>', 1)[0]
    assert 'Kingpin Seal Kit' not in tech_topbar and 'Parts newly available' not in tech_topbar

    _, _, store_attention_b = get_bytes('/spareparts/alerts', store_op)
    store_attention_html = store_attention_b.decode('utf-8', 'replace')
    store_topbar = store_attention_html.split('<header class="topbar">', 1)[1].split('</header>', 1)[0]
    assert 'Parts newly available' in store_topbar and 'Kingpin Seal Kit' in store_topbar
    assert 'workorders/view/' not in store_topbar, 'Store attention linked to an unauthorized WO!'

    # CEO is redirected away from the endpoint and receives neither operational utility.
    ceo_op = session_opener('ceo@ci.local')
    ceo_response = ceo_op.open(BASE + '/search?q=WO-CI-TEAM-01')
    ceo_search_html = ceo_response.read().decode('utf-8', 'replace')
    assert '/dashboard' in ceo_response.geturl() and not ceo_search_html.lstrip().startswith('{')
    ceo_dashboard = get('/dashboard', ceo_op)
    ceo_topbar = ceo_dashboard.split('<header class="topbar">', 1)[1].split('</header>', 1)[0]
    assert 'global-find-trigger' not in ceo_topbar and 'operational-attention' not in ceo_topbar

    # Fetch alert ID from Manager alerts page; verify unassigned Technician CANNOT mark it read (SP-01)!
    _, _, m_al_b = get_bytes('/spareparts/alerts', mgr_op)
    m_al_html = m_al_b.decode('utf-8', 'replace')
    al_m = re.search(r'spareparts/mark_read/(\d+)', m_al_html)
    assert al_m, 'Expected New alert on /spareparts/alerts for Manager'
    peer_al_id = int(al_m.group(1))

    _, _, t_sp_b = get_bytes('/spareparts', tech_op)
    t_sp_html = t_sp_b.decode('utf-8', 'replace')
    assert 'id="spOrderBtn"' not in t_sp_html and '>View Orders<' not in t_sp_html
    post_op(tech_op, f'/spareparts/mark_read/{peer_al_id}', {'csrf_token_wms': csrf_from(t_sp_html)})

    # Alert must STILL be New for Manager because Technician did not own WO-CI-HOLD-02!
    _, _, m_al2_b = get_bytes('/spareparts/alerts', mgr_op)
    m_al2_html = m_al2_b.decode('utf-8', 'replace')
    assert f'spareparts/mark_read/{peer_al_id}' in m_al2_html, 'Horizontal tamper: Technician marked peer WO alert read!'

    # Manager (whitelisted in MANAGER_WRITE_WHITELIST) marks alert read cleanly
    post_op(mgr_op, f'/spareparts/mark_read/{peer_al_id}', {'csrf_token_wms': csrf_from(m_al2_html)})
    _, _, m_al3_b = get_bytes('/spareparts/alerts', mgr_op)
    assert f'spareparts/mark_read/{peer_al_id}' not in m_al3_b.decode('utf-8', 'replace')

    # Storekeeper allocates 2 sets against EX 006 demand line
    _, _, st_sp_b = get_bytes('/spareparts', store_op)
    st_sp_html = st_sp_b.decode('utf-8', 'replace')
    alloc_m = re.search(r'spareparts/allocate/(\d+)', st_sp_html)
    assert alloc_m and 'id="spOrderBtn"' in st_sp_html
    sp_line1_id = int(alloc_m.group(1))
    st_al_post, _, _ = post_op(store_op, f'/spareparts/allocate/{sp_line1_id}', {
        'csrf_token_wms': csrf_from(st_sp_html),
        'qty': '2',
    })
    assert st_al_post == 200

    # Manager read-only board, summary_json, UTF-8 BOM CSV & Styled XLSX verification
    st_m_sp, _, m_sp_b = get_bytes('/spareparts', mgr_op)
    m_sp_html = m_sp_b.decode('utf-8', 'replace')
    assert (
        st_m_sp == 200
        and 'EX 006' in m_sp_html
        and 'C-HEADT 007' in m_sp_html
        and 'Allocated' in m_sp_html
        and '>Allocate<' not in m_sp_html
        and '>Issue<' not in m_sp_html
        and 'id="spOrderBtn"' not in m_sp_html
    )
    st_m_spj, _, m_spj_b = get_bytes(f'/spareparts/summary_json/{sp_line1_id}', mgr_op)
    m_spj = json.loads(m_spj_b.decode('utf-8'))
    assert (
        st_m_spj == 200
        and m_spj.get('ok') is True
        and m_spj['line']['can_write'] is False
        and m_spj['line']['registration_no'] == 'EX 006'
        and m_spj['line']['avail_status'] == 'Allocated'
        and len(m_spj['allocations']) == 1
    )
    st_m_spcsv, _, m_spcsv_b = get_bytes('/spareparts/export_csv', mgr_op)
    m_spcsv_txt = m_spcsv_b.decode('utf-8-sig')
    assert (
        st_m_spcsv == 200
        and m_spcsv_b.startswith(b'\xef\xbb\xbf')
        and "'=SUM(3,4)" in m_spcsv_txt
        and 'EX 006' in m_spcsv_txt
        and 'C-HEADT 007' in m_spcsv_txt
    )
    st_m_spxls, _, m_spxls_b = get_bytes('/spareparts/export_xlsx', mgr_op)
    assert st_m_spxls == 200 and m_spxls_b[:4] == b'PK\x03\x04'

    # Suppliers Directory SUP-01..SUP-06 Live Contacts, Catalog, FK Delete Guard, RBAC & Formula-Safe CSV/XLSX Verification
    import io
    import zipfile

    # Technician (role_id = 2) is barred from /suppliers per ACCESS_MATRIX['suppliers'] = [1, 3, 4]
    t_sup_resp = tech_op.open(BASE + '/suppliers')
    t_sup_url = t_sup_resp.geturl()
    t_sup_resp.read()
    assert '/dashboard' in t_sup_url, f'Expected Technician redirect to /dashboard on /suppliers, got {t_sup_url}'

    # Storekeeper adds formula-prefixed contact + catalog quote to Mantrac CAT Intl (sup_id_new, linked to EX 006 PO)
    _, _, st_sv_b = get_bytes(f'/suppliers/view/{sup_id_new}', store_op)
    st_c_res, _, _ = post_op(store_op, f'/suppliers/add_contact/{sup_id_new}', {
        'csrf_token_wms': csrf_from(st_sv_b.decode('utf-8', 'replace')),
        'name': '=SUM(7,8) Hans Weber',
        'role': 'Export Director',
        'phone': '+49-30-5550199',
        'email': 'hans@mantrac-cat.example',
    })
    assert st_c_res == 200

    _, _, st_sv2_b = get_bytes(f'/suppliers/view/{sup_id_new}', store_op)
    st_cat_res, _, _ = post_op(store_op, f'/suppliers/add_catalog_item/{sup_id_new}', {
        'csrf_token_wms': csrf_from(st_sv2_b.decode('utf-8', 'replace')),
        'part_number': '295-5609',
        'description': '=SUM(9,1) Boom Seal Kit',
        'unit_price': '362.50',
        'currency': 'USD',
        'moq': '2',
        'lead_time_days': '14',
        'is_preferred': '1',
    })
    assert st_cat_res == 200

    # SUP-04: Attempting hard delete on supplier with linked PO must NOT throw MySQL 1451 HTTP 500
    _, _, st_sv3_b = get_bytes(f'/suppliers/view/{sup_id_new}', store_op)
    st_del_blk, del_blk_url, del_blk_body = post_op(store_op, f'/suppliers/delete/{sup_id_new}', {
        'csrf_token_wms': csrf_from(st_sv3_b.decode('utf-8', 'replace')),
    })
    assert (
        st_del_blk == 200
        and f'/suppliers/view/{sup_id_new}' in del_blk_url
        and 'linked purchase order' in del_blk_body
    ), f'Expected graceful FK guard on /suppliers/delete/{sup_id_new}, got {st_del_blk} {del_blk_url}'

    # Create & cleanly delete an unlinked disposable supplier to prove unlinked deletion still works
    _, _, tmp_new_b = get_bytes('/suppliers/create', store_op)
    st_tmp_c, tmp_sup_url, tmp_sup_body = post_op(store_op, '/suppliers/create', {
        'csrf_token_wms': csrf_from(tmp_new_b.decode('utf-8', 'replace')),
        'company_name': 'Disposable Vendor Co',
        'country': 'Tanzania',
        'currency': 'TZS',
        'status': '<script>alert(1)</script>',
    })
    assert st_tmp_c == 200 and '/suppliers/view/' in tmp_sup_url and '<script>alert(1)</script>' not in tmp_sup_body
    tmp_sup_id = int(tmp_sup_url.rstrip('/').split('/')[-1])
    st_tmp_d, tmp_del_url, _ = post_op(store_op, f'/suppliers/delete/{tmp_sup_id}', {
        'csrf_token_wms': csrf_from(tmp_sup_body),
    })
    assert st_tmp_d == 200 and tmp_del_url.rstrip('/').endswith('/suppliers')

    # Manager (role_id = 4) view-only RBAC on /suppliers, /suppliers/view/{id}, /suppliers/find_part, summary_json & exports
    st_m_sup, _, m_sup_b = get_bytes('/suppliers', mgr_op)
    m_sup_html = m_sup_b.decode('utf-8', 'replace')
    assert (
        st_m_sup == 200
        and 'ALPROM TRADERS LTD' in m_sup_html
        and 'Mantrac CAT Intl' in m_sup_html
        and 'EX 006' in m_sup_html
        and 'id="supAddBtn"' not in m_sup_html
        and 'Edit Vendor' not in m_sup_html
        and '+ New Order' not in m_sup_html
    )

    st_m_sv, _, m_sv_b = get_bytes(f'/suppliers/view/{sup_id_new}', mgr_op)
    m_sv_html = m_sv_b.decode('utf-8', 'replace')
    assert (
        st_m_sv == 200
        and 'EX 006' in m_sv_html
        and 'Export Director' in m_sv_html
        and '295-5609' in m_sv_html
        and '+ Add Contact' not in m_sv_html
        and '+ Add Catalog Item' not in m_sv_html
        and '+ New Order' not in m_sv_html
    )

    st_m_fp, _, m_fp_b = get_bytes('/suppliers/find_part?q=295-5609', mgr_op)
    m_fp_html = m_fp_b.decode('utf-8', 'replace')
    assert st_m_fp == 200 and 'Mantrac CAT Intl' in m_fp_html and '>Order<' not in m_fp_html

    # Manager mutation attempt on /suppliers/create must redirect to /dashboard
    _, _, mgr_prof_b = get_bytes('/users/profile', mgr_op)
    _, m_mut_url, _ = post_op(mgr_op, '/suppliers/create', {
        'csrf_token_wms': csrf_from(mgr_prof_b.decode('utf-8', 'replace')),
        'company_name': 'Blocked Manager Vendor',
        'country': 'Uganda',
    })
    assert '/dashboard' in m_mut_url

    st_m_sj, _, m_sj_b = get_bytes(f'/suppliers/summary_json/{sup_id_new}', mgr_op)
    m_sj = json.loads(m_sj_b.decode('utf-8'))
    assert (
        st_m_sj == 200
        and m_sj.get('ok') is True
        and m_sj['supplier']['can_write'] is False
        and len(m_sj['contacts']) == 1
        and len(m_sj['catalog']) == 1
        and any(o.get('registration_no') == 'EX 006' for o in m_sj['orders'])
    )

    st_m_scsv, _, m_scsv_b = get_bytes('/suppliers/export_csv', mgr_op)
    m_scsv_txt = m_scsv_b.decode('utf-8-sig')
    assert (
        st_m_scsv == 200
        and m_scsv_b.startswith(b'\xef\xbb\xbf')
        and "'=SUM(7,8) Hans Weber" in m_scsv_txt
        and 'ALPROM TRADERS LTD' in m_scsv_txt
        and 'Mantrac CAT Intl' in m_scsv_txt
        and 'EX 006' in m_scsv_txt
    )

    st_m_sxls, _, m_sxls_b = get_bytes('/suppliers/export_xlsx', mgr_op)
    assert st_m_sxls == 200 and m_sxls_b[:4] == b'PK\x03\x04'
    with zipfile.ZipFile(io.BytesIO(m_sxls_b), 'r') as zf:
        sheet_xml = zf.read('xl/worksheets/sheet1.xml').decode('utf-8')
        shared_xml = zf.read('xl/sharedStrings.xml').decode('utf-8')
        assert '<f>' not in sheet_xml, f'Formula injection <f> leaked into Suppliers XLSX: {sheet_xml[:400]}'
        assert "'=SUM(7,8) Hans Weber" in shared_xml and 'EX 006' in shared_xml

    # Lubricants & Lube Budget LUB-01..LUB-06 End-to-End OEM Variance, Budget Allocation, RBAC & Export Verification
    import datetime
    today_dt = datetime.date.today()
    today_str = today_dt.strftime('%Y-%m-%d')
    cur_yr = str(today_dt.year)
    cur_mo = str(today_dt.month)

    # Manager (role_id = 4) is barred from /lubricants per ACCESS_MATRIX['lubricants'] = [1, 2, 3]
    m_lub_resp = mgr_op.open(BASE + '/lubricants')
    m_lub_url = m_lub_resp.geturl()
    m_lub_resp.read()
    assert '/dashboard' in m_lub_url, f'Expected Manager redirect to /dashboard on /lubricants, got {m_lub_url}'

    # Invalid legacy category must be rejected (never silently converted to Other).
    _, _, sup_lt_b = get_bytes('/lubricants/types', opener)
    st_lt_bad, _, lt_bad_b = post_op(opener, '/lubricants/types', {
        'csrf_token_wms': csrf_from(sup_lt_b.decode('utf-8', 'replace')),
        'name': 'PAO Synthetic Oil',
        'category': 'Brake Fluid',
        'grade': 'PAO 68',
        'unit': 'L',
        'unit_cost': '950.00',
    })
    assert st_lt_bad == 200 and 'Type not saved' in lt_bad_b and 'PAO Synthetic Oil</td>' not in lt_bad_b

    # A valid existing enum category saves with its original value.
    st_lt_c, _, lt_good_b = post_op(opener, '/lubricants/types', {
        'csrf_token_wms': csrf_from(lt_bad_b),
        'name': 'PAO Synthetic Oil',
        'category': 'Other',
        'grade': 'PAO 68',
        'unit': 'L',
        'unit_cost': '950.00',
    })
    assert st_lt_c == 200 and 'PAO Synthetic Oil' in lt_good_b

    # Storekeeper defines OEM spec (28L Engine Oil), saves monthly budget, and logs 2 consumption issues (EX 006 & C-HEADT 007)
    _, _, st_spec_b = get_bytes('/lubricants/specifications', store_op)
    st_spec_html = st_spec_b.decode('utf-8', 'replace')
    et_m = re.search(r'<select\b[^>]*\bname="equipment_type_id"[^>]*>.*?<option value="(\d+)">', st_spec_html, re.S)
    assert et_m, 'Missing equipment_type_id option on /lubricants/specifications'
    st_sp_save, _, _ = post_op(store_op, '/lubricants/specifications', {
        'csrf_token_wms': csrf_from(st_spec_html),
        'equipment_type_id': et_m.group(1),
        'lube_type_id': '1',
        'recommended_qty': '28.0',
        'service_interval_hours': '250',
        'notes': 'CAT sump standard',
    })
    assert st_sp_save == 200

    # Invalid negative specification cannot silently zero an already-saved OEM standard.
    _, _, spec_before_b = get_bytes('/lubricants/specifications', store_op)
    st_sp_bad, _, sp_bad_html = post_op(store_op, '/lubricants/specifications', {
        'csrf_token_wms': csrf_from(spec_before_b.decode('utf-8', 'replace')),
        'equipment_type_id': et_m.group(1),
        'lube_type_id': '1',
        'recommended_qty': '-10',
        'service_interval_hours': '250',
    })
    # Quantity and unit are separate spans; compare rendered text rather than
    # requiring an impossible contiguous substring in the raw HTML.
    sp_bad_text = ' '.join(re.sub(r'<[^>]+>', ' ', sp_bad_html).split())
    assert st_sp_bad == 200 and 'Specification not saved' in sp_bad_text and '28.00 L' in sp_bad_text

    _, _, st_bgt_b = get_bytes(f'/lubricants/budget?year={cur_yr}&month={cur_mo}', store_op)
    st_bgt_save, _, st_bgt_after = post_op(store_op, '/lubricants/budget', {
        'csrf_token_wms': csrf_from(st_bgt_b.decode('utf-8', 'replace')),
        'year': cur_yr,
        'month': cur_mo,
        'budget[1][qty]': '200',
        'budget[1][cost]': '520',
        'budget[2][qty]': '150',
        'budget[2][cost]': '680',
        'budget[999999][qty]': '50',
    })
    assert st_bgt_save == 200 and '104,000.00' in st_bgt_after

    _, _, st_rec_b = get_bytes('/lubricants/record', store_op)
    st_rec_html = st_rec_b.decode('utf-8', 'replace')
    eq1_m = re.search(r'<option value="(\d+)">\s*EX 006\b', st_rec_html)
    eq2_m = re.search(r'<option value="(\d+)">\s*C-HEADT 007\b', st_rec_html)
    assert eq1_m and eq2_m, 'Missing verbatim EX 006 / C-HEADT 007 options on /lubricants/record'

    st_r1, _, _ = post_op(store_op, '/lubricants/record', {
        'csrf_token_wms': csrf_from(st_rec_html),
        'equipment_id': eq1_m.group(1),
        'lube_type_id': '1',
        'quantity_used': '32.5',
        'equipment_hours': '1425',
        'consumption_date': today_str,
        'work_order_id': str(wo1_id),
        'variance_reason': '=SUM(2,3) Hose burst top-up',
        'notes': 'Store drum #4',
    })
    assert st_r1 == 200

    _, _, st_rec2_b = get_bytes('/lubricants/record', store_op)
    st_r2, _, _ = post_op(store_op, '/lubricants/record', {
        'csrf_token_wms': csrf_from(st_rec2_b.decode('utf-8', 'replace')),
        'equipment_id': eq2_m.group(1),
        'lube_type_id': '2',
        'quantity_used': '20.0',
        'equipment_hours': '98450',
        'consumption_date': today_str,
        'variance_reason': 'Scheduled hydraulic top-up',
    })
    assert st_r2 == 200

    # Technician (role_id = 2) is strictly Read-Only on /lubricants & blocked from /lubricants/budget + spec/budget POSTs
    t_bgt_resp = tech_op.open(BASE + '/lubricants/budget')
    t_bgt_url = t_bgt_resp.geturl()
    t_bgt_resp.read()
    assert t_bgt_url.rstrip('/').endswith('/lubricants'), f'Expected Technician redirect to /lubricants on /lubricants/budget, got {t_bgt_url}'

    _, _, t_prof_b = get_bytes('/users/profile', tech_op)
    _, t_bgt_post_url, _ = post_op(tech_op, '/lubricants/budget', {
        'csrf_token_wms': csrf_from(t_prof_b.decode('utf-8', 'replace')),
        'year': cur_yr,
        'month': cur_mo,
        'budget[1][qty]': '999999',
        'budget[1][cost]': '999999',
    })
    assert t_bgt_post_url.rstrip('/').endswith('/lubricants')
    # Verify Technician's tampered POST did NOT alter Storekeeper's 104,000.00 Engine Oil budget!
    _, _, st_bgt_chk_b = get_bytes(f'/lubricants/budget?year={cur_yr}&month={cur_mo}', store_op)
    assert '104,000.00' in st_bgt_chk_b.decode('utf-8', 'replace')

    st_t_lub, _, t_lub_b = get_bytes('/lubricants', tech_op)
    t_lub_html = t_lub_b.decode('utf-8', 'replace')
    lub_id_m = re.search(r'data-lub-open="(\d+)"', t_lub_html)
    assert (
        st_t_lub == 200
        and lub_id_m
        and 'EX 006' in t_lub_html
        and 'C-HEADT 007' in t_lub_html
        and 'id="lubRecordBtn"' not in t_lub_html
        and '+ Record Consumption' not in t_lub_html
        and '/lubricants/budget' not in t_lub_html
    )

    # Verify summary_json, direct Storekeeper/Technician CSV & XLSX exports, and Budget CSV/XLSX exports
    st_t_lj, _, t_lj_b = get_bytes(f'/lubricants/summary_json/{lub_id_m.group(1)}', tech_op)
    t_lj = json.loads(t_lj_b.decode('utf-8'))
    assert st_t_lj == 200 and t_lj.get('ok') is True and t_lj['record']['can_write'] is False

    st_s_lcsv, _, s_lcsv_b = get_bytes('/lubricants/export_csv', store_op)
    s_lcsv_txt = s_lcsv_b.decode('utf-8-sig')
    assert (
        st_s_lcsv == 200
        and s_lcsv_b.startswith(b'\xef\xbb\xbf')
        and "'=SUM(2,3) Hose burst top-up" in s_lcsv_txt
        and 'EX 006' in s_lcsv_txt
        and 'C-HEADT 007' in s_lcsv_txt
    )

    st_s_lxls, _, s_lxls_b = get_bytes('/lubricants/export_xlsx', store_op)
    assert st_s_lxls == 200 and s_lxls_b[:4] == b'PK\x03\x04'
    with zipfile.ZipFile(io.BytesIO(s_lxls_b), 'r') as zf:
        l_sheet = zf.read('xl/worksheets/sheet1.xml').decode('utf-8')
        l_shared = zf.read('xl/sharedStrings.xml').decode('utf-8')
        assert '<f>' not in l_sheet, f'Formula injection <f> leaked into Lubricants XLSX: {l_sheet[:400]}'
        assert "'=SUM(2,3) Hose burst top-up" in l_shared and 'EX 006' in l_shared

    st_b_csv, _, b_csv_b = get_bytes(f'/lubricants/budget_csv?year={cur_yr}&month={cur_mo}', store_op)
    assert st_b_csv == 200 and b_csv_b.startswith(b'\xef\xbb\xbf') and 'Engine Oil' in b_csv_b.decode('utf-8-sig')
    st_b_xls, _, b_xls_b = get_bytes(f'/lubricants/budget_xlsx?year={cur_yr}&month={cur_mo}', store_op)
    assert st_b_xls == 200 and b_xls_b[:4] == b'PK\x03\x04'

    # Also smoke-test /lubricants/forecast 200 response with zero PHP errors
    st_fc, _, fc_b = get_bytes('/lubricants/forecast', store_op)
    assert st_fc == 200 and 'Lubricant Consumption Forecast' in fc_b.decode('utf-8', 'replace')

    print('ALL LIVE ATTENDANCE WORKFLOW, WORKFORCE DIRECTORY, WORK ORDERS, INSPECTIONS, BACKLOG, INVENTORY, SPARE ORDERS, PENDING SPARES, SUPPLIERS & LUBRICANTS E2E CHECKS PASSED!')
except BaseException:
    tb = traceback.format_exc().replace('\n', '%0A')
    print(f'::error title=PythonE2E::{tb}', flush=True)
    raise
