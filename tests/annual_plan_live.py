"""Disposable MySQL/MariaDB + authenticated HTTP check for annual planning.

Run after creating admin@ci.local, mgr@ci.local, roster@ci.local and
rosterpay@ci.local with scripts/create-admin.php. Requires a running PHP HTTP
server and WMS_TEST_DESTRUCTIVE=1. Never run against a production database.
"""
import csv
import datetime as dt
import http.cookiejar
import io
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
TODAY = dt.date.today()
YEAR = TODAY.year
FUTURE = YEAR + 1
WORK_DATE = TODAY.isoformat()
REPLACEMENT = (TODAY + dt.timedelta(days=4)).isoformat()
LEAVE_DATE = (TODAY + dt.timedelta(days=1)).isoformat()


def db(query):
    env = dict(os.environ, MYSQL_PWD=os.environ.get('WMS_DB_PASS', ''))
    result = subprocess.run([
        'mysql', '-h', os.environ.get('WMS_DB_HOST', '127.0.0.1'),
        '-P', os.environ.get('WMS_DB_PORT', '3306'),
        '-u', os.environ.get('WMS_DB_USER', 'root'),
        os.environ.get('WMS_DB_NAME', 'wms_fresh'), '-N', '-B', '--raw', '-e', query,
    ], env=env, text=True, capture_output=True, check=True)
    return [line.split('\t') for line in result.stdout.splitlines()]


def page(op, path):
    try:
        r = op.open(BASE + path, timeout=45)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.geturl(), r.read()


def text(op, path):
    status, url, body = page(op, path)
    html = body.decode('utf-8', 'replace')
    assert status == 200 and not re.search(r'Fatal error|Database Error|Uncaught', html), (path, status, url, html[:400])
    return html


def csrf(html):
    m = re.search(r'name=["\x27]csrf_token_wms["\x27]\s+value=["\x27]([^"\x27]+)', html)
    assert m, 'CSRF field missing on authenticated form'
    return m.group(1)


def session(email):
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    token = csrf(text(op, '/login'))
    payload = urllib.parse.urlencode({'email': email, 'password': 'AdminPass123!', 'csrf_token_wms': token}).encode()
    r = op.open(urllib.request.Request(BASE + '/login', data=payload), timeout=45)
    body = r.read().decode('utf-8', 'replace')
    assert r.status == 200 and '/dashboard' in r.geturl() and 'Sign Out' in body, (email, r.geturl(), body[:300])
    return op


def submit(op, path, fields, token_path):
    token = csrf(text(op, token_path))
    values = list(fields.items()) + [('csrf_token_wms', token)]
    payload = urllib.parse.urlencode(values, doseq=True).encode()
    try:
        r = op.open(urllib.request.Request(BASE + path, data=payload), timeout=60)
    except urllib.error.HTTPError as e:
        r = e
    status, url, raw = r.status, r.geturl(), r.read()
    html = raw.decode('utf-8', 'replace')
    assert status == 200 and not re.search(r'Fatal error|Database Error|Uncaught', html), (path, status, url, html[:400])
    return url, html


def count(query):
    return int(db(query)[0][0])


def day(uid, date):
    return db(f"SELECT day_type, COALESCE(shift_id,0), source FROM workforce_plan_days "
              f"WHERE user_id={uid} AND work_date='{date}'")[0]


def extra(uid, date):
    return db(f"SELECT id, status, compensation, settlement, COALESCE(replacement_date,''), "
              f"COALESCE(replaced_shift_id,0), COALESCE(confirmed_hours,0), COALESCE(payroll_reference,'') "
              f"FROM workforce_plan_extra_days WHERE user_id={uid} AND work_date='{date}'")[0]


try:
    admin = session('admin@ci.local')
    manager = session('mgr@ci.local')
    worker = session('roster@ci.local')
    pay_worker = session('rosterpay@ci.local')
    worker_id, employee_id = db("SELECT id,employee_id FROM users WHERE email='roster@ci.local'")[0]
    pay_id = int(db("SELECT id FROM users WHERE email='rosterpay@ci.local'")[0][0])
    worker_id = int(worker_id)
    people = [int(row[0]) for row in db("SELECT id FROM users WHERE status IN ('Active','On Leave') ORDER BY id")]
    assert worker_id in people and pay_id in people and 1 <= len(people) <= 100

    # Full future year is materialised as dated Shift and Off rows, not one
    # assignment per week. Managers can preview it; workers cannot see a draft.
    future_url = f'/team/annual_roster?year={FUTURE}&user={worker_id}'
    html = text(admin, future_url)
    assert set(people) <= {int(s) for s in re.findall(r'name="user_ids\[\]" value="(\d+)"', html)}
    pattern = {'year': str(FUTURE), 'cycle_days': '7', 'user_ids[]': people}
    pattern.update({f'pattern[{i}]': '1' if i < 5 else 'Off' for i in range(7)})
    url, _ = submit(admin, '/team/roster_pattern_save', pattern, future_url)
    assert '/team/annual_roster' in url and db(f'SELECT status FROM workforce_plan_years WHERE plan_year={FUTURE}')[0][0] == 'Draft'
    assert count(f'SELECT COUNT(*) FROM workforce_plan_days WHERE user_id={worker_id} AND YEAR(work_date)={FUTURE}') in (365, 366)
    assert count(f"SELECT COUNT(*) FROM workforce_plan_days WHERE user_id={worker_id} AND YEAR(work_date)={FUTURE} AND day_type='Off'") > 45
    assert 'Draft preview' in text(manager, future_url)
    assert 'name="user_ids[]"' not in text(manager, future_url)
    assert 'There is no published plan' in text(worker, future_url)
    status, _, _ = page(worker, f'/team/annual_roster_csv?year={FUTURE}')
    assert status == 404, 'A worker must not export a draft year'
    # A manager's attempted POST cannot publish a plan.
    submit(manager, '/team/roster_publish', {'year': FUTURE}, '/users/profile')
    assert db(f'SELECT status FROM workforce_plan_years WHERE plan_year={FUTURE}')[0][0] == 'Draft'

    # Date-specific draft override and a leave block that crosses into an
    # unpublished following year. Only the published portion reaches staff.
    override = f'{FUTURE}-01-03'
    submit(admin, '/team/roster_day_save', {
        'user_id': worker_id, 'date': override, 'day_type': 'Holiday',
    }, future_url)
    assert day(worker_id, override)[:2] == ['Holiday', '0']
    leave_start, leave_end = f'{FUTURE}-12-30', f'{FUTURE + 1}-01-02'
    submit(admin, '/team/roster_leave_save', {
        'year': FUTURE, 'user_id': worker_id, 'start_date': leave_start,
        'end_date': leave_end, 'leave_type': 'Annual Leave', 'note': 'Year boundary',
    }, future_url)
    assert db(f'SELECT status FROM workforce_plan_years WHERE plan_year={FUTURE + 1}')[0][0] == 'Draft'
    assert count(f"SELECT COUNT(*) FROM workforce_plan_leaves WHERE user_id={worker_id} "
                 f"AND start_date='{leave_start}' AND end_date='{leave_end}' AND status='Scheduled'") == 1
    submit(admin, '/team/roster_publish', {'year': FUTURE}, future_url)
    assert db(f'SELECT status FROM workforce_plan_years WHERE plan_year={FUTURE}')[0][0] == 'Published'
    staff_future = text(worker, future_url)
    assert 'Published' in staff_future and 'Annual Leave' in staff_future and f'02 Jan {FUTURE + 1}' not in staff_future
    assert 'There is no published plan' in text(worker, f'/team/annual_roster?year={FUTURE + 1}')
    status, _, data = page(worker, f'/team/annual_roster_csv?year={FUTURE}')
    assert status == 200 and data.startswith(b'\xef\xbb\xbf')
    records = list(csv.DictReader(io.StringIO(data.decode('utf-8-sig'))))
    assert len(records) in (365, 366) and records[-1]['Date'] == f'{FUTURE}-12-31'
    assert records[-1]['Plan'] == 'Leave' and records[-1]['Plan State'] == 'Published'

    # Published overrides require a reason and are auditable. A failed write
    # must leave the former row intact.
    before = day(worker_id, override)
    submit(admin, '/team/roster_day_save', {
        'user_id': worker_id, 'date': override, 'day_type': 'Off',
    }, future_url)
    assert day(worker_id, override) == before
    submit(admin, '/team/roster_day_save', {
        'user_id': worker_id, 'date': override, 'day_type': 'Off',
        'reason': 'Approved shift swap',
    }, future_url)
    assert day(worker_id, override)[:2] == ['Off', '0']
    assert count(f"SELECT COUNT(*) FROM workforce_plan_events WHERE user_id={worker_id} "
                 f"AND plan_year={FUTURE} AND event_type='Day changed'") >= 2

    # Publish this year's remaining dates, then authorise two different
    # compensation routes on an actual Off day. Attendance is NOT generated.
    current_url = f'/team/annual_roster?year={YEAR}&user={worker_id}'
    pattern['year'] = YEAR
    submit(admin, '/team/roster_pattern_save', pattern, current_url)
    for uid in (worker_id, pay_id):
        submit(admin, '/team/roster_day_save', {
            'user_id': uid, 'date': WORK_DATE, 'day_type': 'Off',
        }, current_url)
    submit(admin, '/team/roster_publish', {'year': YEAR}, current_url)
    assert db(f'SELECT status FROM workforce_plan_years WHERE plan_year={YEAR}')[0][0] == 'Published'
    assert day(worker_id, WORK_DATE)[0] == 'Off'
    assert count(f"SELECT COUNT(*) FROM attendance WHERE user_id={worker_id} AND date='{WORK_DATE}'") == 0
    assert count(f"SELECT COUNT(*) FROM attendance WHERE user_id={pay_id} AND date='{WORK_DATE}'") == 0
    for uid, comp in ((worker_id, 'TimeOff'), (pay_id, 'Pay')):
        submit(admin, '/team/roster_extra_save', {
            'user_id': uid, 'work_date': WORK_DATE, 'shift_id': 1,
            'compensation': comp, 'reason': 'Emergency maintenance call-in',
        }, current_url)
        assert extra(uid, WORK_DATE)[1:4] == ['Planned', comp, 'Open']
    assert day(worker_id, WORK_DATE)[0] == 'Off'
    attendance_url = f'/team/attendance?date={WORK_DATE}'
    submit(admin, '/team/attendance', {
        'date': WORK_DATE,
        **{f'attendance[{uid}][{key}]': val for uid in (worker_id, pay_id)
           for key, val in (('status', 'Present'), ('check_in', '08:00'), ('check_out', '16:00'))},
    }, attendance_url)
    for uid in (worker_id, pay_id):
        assert db(f"SELECT status,hours_worked FROM attendance WHERE user_id={uid} AND date='{WORK_DATE}'")[0] == ['Present', '8.00']
        submit(admin, '/team/roster_extra_confirm', {
            'year': YEAR, 'user_id': uid, 'extra_id': int(extra(uid, WORK_DATE)[0]),
        }, current_url)
        assert extra(uid, WORK_DATE)[1] == 'Confirmed' and extra(uid, WORK_DATE)[6] == '8.00'

    payroll_reference = '=SUM(4,5) Payroll CI confirmation'
    submit(admin, '/team/roster_extra_settle', {
        'year': YEAR, 'user_id': pay_id, 'extra_id': int(extra(pay_id, WORK_DATE)[0]),
        'action': 'pay', 'reason': payroll_reference,
    }, current_url)
    assert extra(pay_id, WORK_DATE)[3] == 'Paid' and extra(pay_id, WORK_DATE)[7] == payroll_reference
    status, _, data = page(admin, f'/team/annual_roster_csv?year={YEAR}&user={pay_id}')
    assert status == 200 and "'=SUM(4,5) Payroll" in data.decode('utf-8-sig')

    # A compensatory Off replaces a published Shift, retains the worked Off
    # date as its source, and can be undone before it is actually taken.
    replace_year = int(REPLACEMENT[:4])
    replace_url = f'/team/annual_roster?year={replace_year}&user={worker_id}'
    submit(admin, '/team/roster_day_save', {
        'user_id': worker_id, 'date': REPLACEMENT, 'day_type': 'shift:1',
        'reason': 'Approved replacement coverage',
    }, replace_url)
    assert day(worker_id, REPLACEMENT)[0] == 'Shift'
    entry_id = int(extra(worker_id, WORK_DATE)[0])
    for _ in range(2):
        submit(admin, '/team/roster_extra_settle', {
            'year': YEAR, 'user_id': worker_id, 'extra_id': entry_id,
            'action': 'schedule', 'replacement_date': REPLACEMENT,
        }, current_url)
        assert day(worker_id, REPLACEMENT)[0] == 'Off'
        assert extra(worker_id, WORK_DATE)[3:6] == ['Scheduled', REPLACEMENT, '1']
        if _ == 0:
            submit(admin, '/team/roster_extra_settle', {
                'year': YEAR, 'user_id': worker_id, 'extra_id': entry_id,
                'action': 'undo', 'reason': 'Replacement rescheduled',
            }, current_url)
            assert day(worker_id, REPLACEMENT)[:2] == ['Shift', '1']
            assert extra(worker_id, WORK_DATE)[3] == 'Open'
    submit(admin, '/team/roster_extra_settle', {
        'year': YEAR, 'user_id': worker_id, 'extra_id': entry_id, 'action': 'take',
    }, current_url)
    assert extra(worker_id, WORK_DATE)[3] == 'Scheduled', 'A future replacement cannot be marked taken'
    assert 'Replacement Off' in text(worker, replace_url)

    # Imminent supervisor-planned leave prompts both start and return notices
    # in the worker's bell, without creating an employee leave request.
    notice_year = int(LEAVE_DATE[:4])
    notice_url = f'/team/annual_roster?year={notice_year}&user={worker_id}'
    submit(admin, '/team/roster_leave_save', {
        'year': notice_year, 'user_id': worker_id,
        'start_date': LEAVE_DATE, 'end_date': LEAVE_DATE,
        'leave_type': 'Annual Leave', 'note': 'Tomorrow off site',
    }, notice_url)
    assert count(f"SELECT COUNT(*) FROM leave_requests WHERE user_id={worker_id} "
                 f"AND start_date='{LEAVE_DATE}'") == 0
    staff_today = text(worker, f'/team/annual_roster?year={YEAR}&user={pay_id}')
    assert 'Planned leave starting soon' in staff_today and 'Return from planned leave' in staff_today
    assert 'Tomorrow off site' in text(worker, notice_url)
    submit(worker, '/team/leave_request', {
        'start_date': LEAVE_DATE, 'end_date': LEAVE_DATE,
        'leave_type': 'Annual Leave', 'reason': 'Duplicate request',
    }, '/team/leave_request')
    assert count(f"SELECT COUNT(*) FROM leave_requests WHERE user_id={worker_id} "
                 f"AND start_date='{LEAVE_DATE}'") == 0

    print('ANNUAL WORKFORCE PLAN LIVE HTTP + DATABASE CHECKS PASSED')
except BaseException:
    print('::error title=AnnualPlanE2E::' + traceback.format_exc().replace('\n', '%0A'), flush=True)
    raise
