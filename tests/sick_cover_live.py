"""Disposable authenticated MySQL/MariaDB test for supervisor-approved sick cover.

Runs AFTER annual_plan_live.py: it reuses the published future year and the
five roster test accounts seeded by CI. Requires WMS_TEST_DESTRUCTIVE=1; never
run against production. No employee medical reason is echoed to candidates.
"""
import datetime as dt
import http.cookiejar
import os
import re
import subprocess
import traceback
import urllib.error
import urllib.parse
import urllib.request

if os.environ.get('WMS_TEST_DESTRUCTIVE') != '1':
    raise SystemExit('Refusing to alter a database without WMS_TEST_DESTRUCTIVE=1')

BASE = os.environ.get('WMS_TEST_BASE', 'http://127.0.0.1:8000')
FUTURE = dt.date.today().year + 1
monday = dt.date(FUTURE, 1, 1)
monday += dt.timedelta(days=(-monday.weekday()) % 7)
D1, D2, D3 = [(monday + dt.timedelta(days=n)).isoformat() for n in (1, 2, 3)]


def db(query):
    env = dict(os.environ, MYSQL_PWD=os.environ.get('WMS_DB_PASS', ''))
    result = subprocess.run([
        'mysql', '-h', os.environ.get('WMS_DB_HOST', '127.0.0.1'),
        '-P', os.environ.get('WMS_DB_PORT', '3306'), '-u', os.environ.get('WMS_DB_USER', 'root'),
        os.environ.get('WMS_DB_NAME', 'wms_fresh'), '-N', '-B', '--raw', '-e', query,
    ], env=env, text=True, capture_output=True, check=True)
    return [line.split('\t') for line in result.stdout.splitlines()]


def count(query):
    return int(db(query)[0][0])


def page(op, path):
    try:
        r = op.open(BASE + path, timeout=45)
    except urllib.error.HTTPError as error:
        r = error
    return r.status, r.geturl(), r.read()


def text(op, path):
    code, url, raw = page(op, path)
    html = raw.decode('utf-8', 'replace')
    assert code == 200 and not re.search(r'Fatal error|Database Error|Uncaught', html), (path, code, url, html[:400])
    return html


def csrf(html):
    match = re.search(r'name=["\x27]csrf_token_wms["\x27]\s+value=["\x27]([^"\x27]+)', html)
    assert match, 'CSRF field missing'
    return match.group(1)


def session(email):
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    token = csrf(text(op, '/login'))
    payload = urllib.parse.urlencode({'email': email, 'password': 'AdminPass123!', 'csrf_token_wms': token}).encode()
    response = op.open(urllib.request.Request(BASE + '/login', data=payload), timeout=45)
    body = response.read().decode('utf-8', 'replace')
    assert response.status == 200 and '/dashboard' in response.geturl() and 'Sign Out' in body
    return op


def submit(op, path, fields, token_path, expected_status=200):
    token = csrf(text(op, token_path))
    payload = urllib.parse.urlencode(list(fields.items()) + [('csrf_token_wms', token)], doseq=True).encode()
    try:
        r = op.open(urllib.request.Request(BASE + path, data=payload), timeout=60)
    except urllib.error.HTTPError as error:
        r = error
    code, url, raw = r.status, r.geturl(), r.read()
    html = raw.decode('utf-8', 'replace')
    assert code == expected_status and not re.search(r'Fatal error|Database Error|Uncaught', html), (path, code, url, html[:400])
    return url, html


def cover(uid, date):
    rows = db(f"SELECT id, cover_user_id, status, cover_mode, COALESCE(extra_day_id,0), extra_created_for_cover "
              f"FROM workforce_plan_cover WHERE absent_user_id={uid} AND work_date='{date}'")
    return rows[0] if rows else None


def approve(op, absent, date, candidate, token_path, expected_status=200, **fields):
    return submit(op, '/team/roster_cover_approve', {
        'user_id': absent, 'date': date, 'cover_user_id': candidate,
        'reason': 'Supervisor checked qualified shift coverage', **fields,
    }, token_path, expected_status)


try:
    admin = session('admin@ci.local')
    manager = session('mgr@ci.local')
    worker = session('roster@ci.local')
    pay_worker = session('rosterpay@ci.local')
    other_worker = session('rostersecond@ci.local')
    ids = {name: int(db(f"SELECT id FROM users WHERE email='{name}@ci.local'")[0][0])
           for name in ('roster', 'rosterpay', 'rosterbackup', 'rostersecond', 'rosterremote')}
    absent, on_shift, off, second, remote = [ids[name] for name in ids]
    assert db(f'SELECT status FROM workforce_plan_years WHERE plan_year={FUTURE}')[0][0] == 'Published'
    db("UPDATE users SET site='Main Workshop' WHERE email IN "
       "('roster@ci.local','rosterpay@ci.local','rosterbackup@ci.local','rostersecond@ci.local')")
    db("UPDATE users SET site='Remote Yard' WHERE email='rosterremote@ci.local'")
    # A matching named certificate is a conservative proxy for competence.
    db(f"INSERT INTO certifications (user_id,cert_name,issued_date,expiry_date) "
       f"VALUES ({absent},'Hydraulic Safety','{dt.date.today().isoformat()}','{FUTURE}-12-31')")

    month = D1[:7]
    board = f'/team/roster_cover?month={month}'
    url1 = f'/team/roster_cover?user={absent}&date={D1}'
    url2 = f'/team/roster_cover?user={absent}&date={D2}'
    roster = f'/team/annual_roster?year={FUTURE}&user={absent}'
    submit(admin, '/team/roster_day_save', {
        'user_id': off, 'date': D2, 'day_type': 'Off', 'reason': 'Standby for urgent cover',
    }, roster)
    assert db(f"SELECT day_type FROM workforce_plan_days WHERE user_id={off} AND work_date='{D2}'")[0][0] == 'Off'
    submit(admin, '/team/roster_leave_save', {
        'user_id': absent, 'start_date': D1, 'end_date': D2,
        'leave_type': 'Sick Leave', 'note': 'Supervisor recorded absence',
    }, roster)
    assert 'Needs cover' in text(admin, board)
    assert 'Sick Leave' in text(worker, roster)
    status, _, _ = page(worker, url1)
    assert status == 404, 'Employee must not read the colleague/eligibility finder'

    # A missing required certificate prevents even a same-site, same-shift
    # candidate from being suggested as ready.
    no_cert = text(admin, url1)
    assert 'Missing current certificate: Hydraulic Safety' in no_cert
    assert 'No safe candidate found.' in no_cert
    assert not re.search(r'<input[^>]+name="cover_user_id" value="' + str(on_shift) + r'"', no_cert)
    db(f"INSERT INTO certifications (user_id,cert_name,issued_date,expiry_date) "
       f"VALUES ({on_shift},'Hydraulic Safety','{dt.date.today().isoformat()}','{FUTURE}-12-31'),"
       f"({off},'Hydraulic Safety','{dt.date.today().isoformat()}','{FUTURE}-12-31')")
    shortlist = text(admin, url1)
    assert 'CI Payroll' in shortlist and 'CI Backup' in shortlist
    assert 'CI Remote' in shortlist and 'Work site is missing or does not match' in shortlist
    manager_preview = text(manager, url1)
    assert 'CI Payroll' in manager_preview and 'name="cover_user_id"' not in manager_preview
    approve(manager, absent, D1, on_shift, '/users/profile', expected_status=404, capacity_checked='1')
    assert cover(absent, D1) is None, 'Manager must never approve'
    approve(admin, absent, D1, on_shift, url1)
    assert cover(absent, D1) is None, 'Capacity check is required'
    approve(admin, absent, D1, on_shift, url1, capacity_checked='1')
    c1 = cover(absent, D1)
    assert c1[1:4] == [str(on_shift), 'Assigned', 'OnShift'] and c1[4] == '0'
    assert count(f'SELECT COUNT(*) FROM workforce_plan_cover WHERE id={c1[0]} AND availability_confirmed_at IS NOT NULL') == 0
    approve(admin, absent, D1, on_shift, url1, capacity_checked='1')
    assert cover(absent, D1) == c1, 'Repeated POST must not create another cover'
    assert count(f"SELECT COUNT(*) FROM attendance WHERE user_id={on_shift} AND date='{D1}'") == 0
    assert 'Cover arranged' in text(worker, roster)
    pay_calendar = f'/team/annual_roster?year={FUTURE}&user={on_shift}'
    assert 'Covering team Shift' in text(pay_worker, pay_calendar)
    assert 'Find cover' not in text(pay_worker, pay_calendar)

    # Sick-shift cover prevents roster regeneration, shift overrides and new
    # leave for its candidate or for the absent worker until cover is undone.
    original_shift = db(f"SELECT day_type FROM workforce_plan_days WHERE user_id={on_shift} AND work_date='{D1}'")[0][0]
    submit(admin, '/team/roster_day_save', {
        'user_id': on_shift, 'date': D1, 'day_type': 'Off', 'reason': 'Unexpected reschedule',
    }, roster)
    assert db(f"SELECT day_type FROM workforce_plan_days WHERE user_id={on_shift} AND work_date='{D1}'")[0][0] == original_shift
    submit(admin, '/team/roster_leave_save', {
        'user_id': on_shift, 'start_date': D1, 'end_date': D1, 'leave_type': 'Annual Leave',
    }, roster)
    assert count(f"SELECT COUNT(*) FROM workforce_plan_leaves WHERE user_id={on_shift} AND start_date='{D1}' AND status='Scheduled'") == 0
    submit(admin, '/team/roster_leave_end', {
        'year': FUTURE, 'user_id': absent,
        'leave_id': int(db(f"SELECT id FROM workforce_plan_leaves WHERE user_id={absent} AND start_date='{D1}'")[0][0]),
        'reason': 'Returned earlier than expected',
    }, roster)
    assert count(f"SELECT COUNT(*) FROM workforce_plan_leaves WHERE user_id={absent} AND status='Scheduled' AND start_date='{D1}'") == 1

    # Same candidate cannot be assigned to two absent workers on one date.
    submit(admin, '/team/roster_leave_save', {
        'user_id': second, 'start_date': D1, 'end_date': D1, 'leave_type': 'Sick Leave',
    }, roster)
    other_url = f'/team/roster_cover?user={second}&date={D1}'
    assert 'Already covering another shift' in text(admin, other_url)
    approve(admin, second, D1, on_shift, other_url, capacity_checked='1')
    assert cover(second, D1) is None

    # Off-day worker is not silently assigned; both recorded availability and
    # Pay/TimeOff compensation must be supplied. All writes are atomic.
    assert 'Off · not available' in text(admin, url2)
    approve(admin, absent, D2, off, url2, capacity_checked='1', compensation='TimeOff')
    assert cover(absent, D2) is None
    db(f"UPDATE users SET site='Remote Yard' WHERE id={off}")
    approve(admin, absent, D2, off, url2, capacity_checked='1', availability_confirmed='1', compensation='TimeOff')
    assert cover(absent, D2) is None, 'Eligibility must be rechecked under lock, not trusted from GET'
    db(f"UPDATE users SET site='Main Workshop' WHERE id={off}")
    approve(admin, absent, D2, off, url2, capacity_checked='1', availability_confirmed='1', compensation='TimeOff')
    c2 = cover(absent, D2)
    assert c2[1:4] == [str(off), 'Assigned', 'OffCallIn'] and c2[5] == '1'
    assert count(f'SELECT COUNT(*) FROM workforce_plan_cover WHERE id={c2[0]} AND availability_confirmed_at IS NOT NULL') == 1
    assert db(f"SELECT day_type FROM workforce_plan_days WHERE user_id={off} AND work_date='{D2}'")[0][0] == 'Off'
    assert db(f"SELECT status, compensation FROM workforce_plan_extra_days WHERE id={c2[4]}")[0] == ['Planned', 'TimeOff']
    backup_calendar = f'/team/annual_roster?year={FUTURE}&user={off}'
    assert 'Covering team Shift' in text(admin, backup_calendar)
    status, _, data = page(admin, f'/team/annual_roster_csv?year={FUTURE}&user={off}')
    assert status == 200 and b'Off call-in cover' in data
    submit(manager, '/team/roster_cover_cancel', {'cover_id': c2[0], 'date': D2, 'user_id': absent,
                                                  'reason': 'Manager cannot cancel'}, '/users/profile', expected_status=404)
    assert cover(absent, D2) == c2
    submit(admin, '/team/roster_cover_cancel', {'cover_id': c2[0], 'date': D2, 'user_id': absent,
                                                'reason': 'Worker no longer available'}, url2)
    assert cover(absent, D2)[2] == 'Cancelled'
    assert db(f"SELECT status FROM workforce_plan_extra_days WHERE id={c2[4]}")[0][0] == 'Cancelled'
    assert db(f"SELECT day_type FROM workforce_plan_days WHERE user_id={off} AND work_date='{D2}'")[0][0] == 'Off'
    assert 'Needs cover' in text(admin, url2)
    approve(admin, absent, D2, on_shift, url2, capacity_checked='1')
    assert cover(absent, D2)[0] == c2[0] and cover(absent, D2)[3] == 'OnShift'
    assert count(f"SELECT COUNT(*) FROM workforce_plan_events WHERE user_id={absent} "
                 f"AND event_type IN ('Sick cover assigned','Sick cover cancelled') AND work_date='{D2}'") == 3

    # Approved exceptional Sick Leave requests are also eligible, and cannot
    # be reversed while a cover still relies on their published absence.
    request_form = '/team/leave_request'
    submit(other_worker, request_form, {
        'start_date': D3, 'end_date': D3, 'leave_type': 'Sick Leave', 'reason': 'Unscheduled illness',
    }, request_form)
    leave_id = int(db(f"SELECT id FROM leave_requests WHERE user_id={second} AND start_date='{D3}' AND leave_type='Sick Leave'")[0][0])
    req_url = f'/team/roster_cover?user={second}&date={D3}'
    status, _, _ = page(admin, req_url)
    assert status == 404, 'Pending leave must not open a cover vacancy'
    submit(admin, f'/team/approve_leave/{leave_id}', {'status': 'Approved'}, '/team')
    assert db(f'SELECT status FROM leave_requests WHERE id={leave_id}')[0][0] == 'Approved'
    assert 'Needs cover' in text(admin, req_url)
    approve(admin, second, D3, off, req_url, capacity_checked='1')
    assert cover(second, D3)[2] == 'Assigned'
    submit(admin, f'/team/approve_leave/{leave_id}', {'status': 'Rejected'}, '/team')
    assert db(f'SELECT status FROM leave_requests WHERE id={leave_id}')[0][0] == 'Approved'
    submit(admin, '/team/roster_cover_cancel', {'cover_id': cover(second,D3)[0], 'date': D3, 'user_id': second,
                                                'reason': 'Request was reversed'}, req_url)
    submit(admin, f'/team/approve_leave/{leave_id}', {'status': 'Rejected'}, '/team')
    assert db(f'SELECT status FROM leave_requests WHERE id={leave_id}')[0][0] == 'Rejected'
    status, _, _ = page(admin, req_url)
    assert status == 404, 'Rejected sick leave no longer needs cover'

    print('SICK SHIFT COVER LIVE HTTP + DATABASE CHECKS PASSED')
except BaseException:
    print('::error title=SickCoverE2E::' + traceback.format_exc().replace('\n', '%0A'), flush=True)
    raise
