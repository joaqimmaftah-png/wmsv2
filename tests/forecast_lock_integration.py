#!/usr/bin/env python3
"""Exercise real plan/publish models concurrently against a disposable MySQL DB.

Set WMS_TEST_DESTRUCTIVE=1 and WMS_DB_* for a disposable database imported from
schema.sql with migrations applied. Uses PHP (WMS_TEST_PHP overrides its path),
independent database connections, and explicit file barriers. Not a mock test.
"""
import datetime as dt
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
WORKER = ROOT / 'tests' / 'forecast_lock_worker.php'
PHP = os.environ.get('WMS_TEST_PHP', 'php')
if os.environ.get('WMS_TEST_DESTRUCTIVE') != '1' or not os.environ.get('WMS_DB_NAME'):
    raise SystemExit('Use WMS_TEST_DESTRUCTIVE=1 and WMS_DB_NAME only with a disposable test database.')

procs = []
checks = 0


def check(condition, name):
    global checks
    if not condition:
        raise AssertionError(name)
    checks += 1
    print('PASS:', name, flush=True)


def start(**args):
    p = subprocess.Popen([PHP, str(WORKER), json.dumps(args, separators=(',', ':'))],
                         cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    procs.append(p)
    return p


def finish(p, timeout=100):
    try:
        out, err = p.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        p.kill()
        out, err = p.communicate()
        raise AssertionError(f'worker timed out: stdout={out[-1000:]} stderr={err[-2000:]}')
    if p.returncode != 0:
        raise AssertionError(f'worker failed ({p.returncode}): stdout={out[-1000:]} stderr={err[-2000:]}')
    try:
        envelope = json.loads(out.strip().splitlines()[-1])
    except (ValueError, IndexError) as exc:
        raise AssertionError(f'bad worker output: {out[-2000:]} stderr={err[-2000:]}') from exc
    if envelope['transaction_active'] or not envelope['debug_restored']:
        raise AssertionError('transaction leaked or debug mode was not restored: ' + repr(envelope))
    return envelope['result']


def run(**args):
    return finish(start(**args))


def wait_for(path, p, timeout=30):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if Path(path).exists():
            return
        if p.poll() is not None:
            out, err = p.communicate()
            raise AssertionError(f'worker exited before {path}: stdout={out[-1000:]} stderr={err[-2000:]}')
        time.sleep(0.025)
    raise AssertionError(f'worker never reached barrier {path}')


def blocked(attempt, entered, p, name):
    wait_for(attempt, p)
    # The holder is paused *inside* the locked transaction. A contender signals
    # before trying to lock and again on entering its callback; it must not enter.
    end = time.monotonic() + 0.6
    while time.monotonic() < end:
        if Path(entered).exists() or p.poll() is not None:
            raise AssertionError(name + ': contender entered or exited before the lock was released')
        time.sleep(0.025)
    check(True, name)


def paths(d, prefix):
    return {k: str(Path(d) / (prefix + '-' + k)) for k in ('attempt', 'entered', 'ready', 'release')}


def release(gate):
    Path(gate['release']).write_text('go')


def assert_frozen(result):
    message = result.get('error', '') or ' '.join(result.get('errors', []))
    return 'frozen' in message.lower() or result.get('frozen') is True


def snapshot_hours(state, eid, week, category='corrective_hours'):
    snaps = state['snapshots']
    assert snaps and snaps[0]['machine'] and int(snaps[0]['machine']['equipment_id']) == eid
    return float(snaps[0]['machine']['daily'][week][category])


def find_job(state, title):
    return next((j for j in state['jobs'] if j['title'] == title), None)


# Widely separated from ordinary operational dates. Refuse a range that already
# contains data and clean up only that verified-empty range plus our own defect.
year = 2100 + secrets.randbelow(400)
jan = dt.date(year, 1, 1)
first = jan + dt.timedelta(days=(-jan.weekday()) % 7)
weeks = [(first + dt.timedelta(weeks=i)).isoformat() for i in range(18)]
end_date = (first + dt.timedelta(weeks=18, days=6)).isoformat()
setup = run(action='prepare', week=weeks[0], end=end_date)
eid, asset = setup['equipment_id'], setup['asset']
alt_id, alt_asset = setup['alternate_id'], setup['alternate_asset']
title = 'WMS CI lock ' + secrets.token_hex(5)
defect_id = None
print('Disposable range:', weeks[0], 'to', end_date, flush=True)

try:
    with tempfile.TemporaryDirectory(prefix='wms-forecast-lock-') as tmp:
        # No public mutator may bypass the owning transaction.
        raw = run(action='unguarded', week=weeks[0], equipment_id=eid)
        check('errors' in raw['job'] and 'error' in raw['publish'] and raw['cell'] is False
              and all('error' in raw[k] for k in ('pm', 'standing', 'unplanned', 'inspections', 'status'))
              and 'errors' in raw['sync'], 'unguarded writes and publication fail closed')
        nested = run(action='nested')
        check('error' in nested['inner'] and nested['outer_still_active'],
              'a pre-existing transaction is rejected without ending it')

        # Missing singleton rows and nontransactional lock tables fail closed.
        run(action='lock_schema', mode='remove')
        try:
            missing = run(action='cell', week=weeks[13], equipment_id=eid)
            check('lock is unavailable' in missing.get('error', '').lower(), 'a missing lock row blocks writes')
        finally:
            run(action='lock_schema', mode='restore')
        run(action='lock_schema', mode='myisam')
        try:
            wrong_engine = run(action='cell', week=weeks[13], equipment_id=eid)
            check('not ready' in wrong_engine.get('error', '').lower(), 'a non-InnoDB lock table blocks writes')
        finally:
            run(action='lock_schema', mode='innodb')

        run(action='lock_schema', mode='events_myisam')
        try:
            unsafe_table = run(action='cell', week=weeks[13], equipment_id=eid)
            check('not transaction-ready' in unsafe_table.get('error', '').lower(),
                  'nontransactional defect or plan tables block writes')
        finally:
            run(action='lock_schema', mode='events_innodb')

        # A planner that gets the lock first is completely visible in the
        # frozen snapshot, including both the job and the derived hours.
        a, b = paths(tmp, 'job-a'), paths(tmp, 'pub-a')
        planner = start(action='save', week=weeks[0], equipment_id=eid, title=title + ' A', **a)
        wait_for(a['ready'], planner)
        pub = start(action='publish', week=weeks[0], equipment_id=eid, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], pub, 'publication waits for an in-flight job and ledger')
        release(a); saved = finish(planner); published = finish(pub)
        state = run(action='inspect', week=weeks[0], equipment_id=eid)
        check(saved.get('id') and published.get('revision') == 1 and state['snapshots'][0]['checksum_valid']
              and any(j['id'] == saved['id'] for j in state['snapshots'][0]['jobs'])
              and snapshot_hours(state, eid, weeks[0]) == 2,
              'committed job and ledger appear together in the frozen baseline')

        # A publisher first makes the contender re-check after waiting.
        a, b = paths(tmp, 'pub-b'), paths(tmp, 'job-b')
        pub = start(action='publish', week=weeks[1], equipment_id=eid, **a)
        wait_for(a['ready'], pub)
        planner = start(action='save', week=weeks[1], equipment_id=eid, title=title + ' B', attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], planner, 'job changes wait while publication is in progress')
        release(a); finish(pub); refused = finish(planner)
        state = run(action='inspect', week=weeks[1], equipment_id=eid)
        check(assert_frozen(refused) and not find_job(state, title + ' B') and not state['ledger'],
              'a waiting job detects the newly published baseline and writes nothing')

        # Moving a job must check its old AND new weeks after obtaining the lock.
        created = run(action='save', week=weeks[2], equipment_id=eid, title=title + ' cross')
        a, b = paths(tmp, 'pub-d'), paths(tmp, 'move-d')
        pub = start(action='publish', week=weeks[3], equipment_id=eid, **a)
        wait_for(a['ready'], pub)
        move = start(action='save', week=weeks[3], equipment_id=eid, title=title + ' cross', job_id=created['id'], attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], move, 'cross-week moves wait for publication')
        release(a); finish(pub); refused = finish(move)
        old = run(action='inspect', week=weeks[2], equipment_id=eid)
        new = run(action='inspect', week=weeks[3], equipment_id=eid)
        check(assert_frozen(refused) and find_job(old, title + ' cross') and not find_job(new, title + ' cross')
              and any(float(x['hours']) == 2 for x in old['ledger']) and not new['ledger'],
              'cross-week refusal preserves the original job and ledger')
        refused_delete = run(action='delete', week=weeks[2], equipment_id=eid, job_id=saved['id'])
        check(assert_frozen(refused_delete), 'deletion of a job touching a frozen week is refused')

        # Two publishers cannot both create revision 1.
        a, b = paths(tmp, 'pub-e1'), paths(tmp, 'pub-e2')
        one = start(action='publish', week=weeks[4], equipment_id=eid, **a)
        wait_for(a['ready'], one)
        two = start(action='publish', week=weeks[4], equipment_id=eid, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], two, 'simultaneous publications serialize')
        release(a); r1 = finish(one); r2 = finish(two)
        state = run(action='inspect', week=weeks[4], equipment_id=eid)
        check((r1.get('revision'), r2.get('revision')) == (1, 2)
              and [int(s['is_baseline']) for s in state['snapshots']] == [1, 0],
              'concurrent publishers create one baseline and one revision')

        # Rollback and connection loss must release the mutex and undo BOTH
        # the job and its derived ledger before a waiting publisher runs.
        for idx, mode in [(5, 'rollback'), (6, 'disconnect')]:
            a, b = paths(tmp, mode + '-job'), paths(tmp, mode + '-pub')
            planner = start(action='save', week=weeks[idx], equipment_id=eid, title=title + ' ' + mode,
                            rollback=(mode == 'rollback'), **a)
            wait_for(a['ready'], planner)
            pub = start(action='publish', week=weeks[idx], equipment_id=eid, attempt=b['attempt'], entered=b['entered'])
            blocked(b['attempt'], b['entered'], pub, mode + ': publisher waits on the incomplete write')
            if mode == 'rollback':
                release(a); check('error' in finish(planner), 'failed job transaction reports its rollback')
            else:
                planner.kill(); planner.communicate(timeout=15)
            finish(pub)
            state = run(action='inspect', week=weeks[idx], equipment_id=eid)
            check(not find_job(state, title + ' ' + mode) and not state['ledger']
                  and not any(j['title'] == title + ' ' + mode for j in state['snapshots'][0]['jobs']),
                  mode + ': no partial job or ledger leaks into publication')

        # A database lock timeout is a clean failure, not a partial mutation.
        a, b = paths(tmp, 'timeout-holder'), paths(tmp, 'timeout-waiter')
        holder = start(action='cell', week=weeks[7], equipment_id=eid, **a)
        wait_for(a['ready'], holder)
        waiter = start(action='save', week=weeks[7], equipment_id=eid, title=title + ' timeout',
                       lock_timeout=1, attempt=b['attempt'], entered=b['entered'])
        wait_for(b['attempt'], waiter)
        timed_out = finish(waiter, timeout=20)
        check('lock is unavailable' in timed_out.get('error', '').lower() and not Path(b['entered']).exists(),
              'lock timeout reports an error without entering the write')
        release(a); finish(holder)
        state = run(action='inspect', week=weeks[7], equipment_id=eid)
        check(not find_job(state, title + ' timeout'), 'timed-out write left no job behind')

        # Actual Backlog::_apply_plan must take the same lock before reading its
        # defect/job; a publication first keeps the committed defect unscheduled.
        defect_id = run(action='create_defect', week=weeks[8], equipment_id=eid, asset=asset, title=title + ' defect')['id']
        a, b = paths(tmp, 'backlog-pub'), paths(tmp, 'backlog-writer')
        pub = start(action='publish', week=weeks[8], equipment_id=eid, **a)
        wait_for(a['ready'], pub)
        backlog = start(action='backlog', week=weeks[8], equipment_id=eid, title=title + ' defect',
                        defect_id=defect_id, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], backlog, 'backlog planning shares the publication lock')
        release(a); finish(pub); out = finish(backlog)
        state = run(action='inspect', week=weeks[8], equipment_id=eid, defect_id=defect_id)
        check('frozen' in out['message'].lower() and state['defect']['schedule_state'] == 'failed'
              and not state['jobs'] and not state['ledger'],
              'backlog defect survives a frozen planning failure without a job')

        # A newer proposal can arrive while the shared plan lock is held. The
        # waiting defect planner must not overwrite it using stale form data.
        run(action='update_defect', defect_id=defect_id, week=weeks[14], minutes=60)
        a, b = paths(tmp, 'request-holder'), paths(tmp, 'stale-request')
        holder = start(action='cell', week=weeks[15], equipment_id=eid, **a)
        wait_for(a['ready'], holder)
        stale = start(action='backlog', week=weeks[14], equipment_id=eid, title=title + ' defect',
                      defect_id=defect_id, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], stale, 'backlog planner waits before reading the current proposal')
        run(action='update_defect', defect_id=defect_id, week=weeks[16])
        release(a); finish(holder); out = finish(stale)
        state = run(action='inspect', week=weeks[14], equipment_id=eid, defect_id=defect_id)
        check('changed while planning' in out['message'].lower()
              and state['defect']['requested_date'] == weeks[16] and int(state['defect']['requested_duration']) == 120
              and state['defect']['schedule_state'] == 'pending_approval' and not state['jobs'],
              'a newer planning proposal is preserved instead of overwritten')

        # A direct Forecast edit can reactivate a previously inactive linked
        # job without changing the defect. Test both lock orders: the active
        # job and its defect must never end up on different machines.
        linked = run(action='save', week=weeks[17], equipment_id=eid, title=title + ' linked',
                     status='Done', source='FromBacklog', defect_id=defect_id)
        assert linked.get('id')
        a, b = paths(tmp, 'equipment-first'), paths(tmp, 'reactivation-waits')
        changing = start(action='change_equipment', equipment_id=alt_id, asset=alt_asset, defect_id=defect_id, **a)
        wait_for(a['ready'], changing)
        activation = start(action='save', week=weeks[17], equipment_id=eid, title=title + ' linked',
                           job_id=linked['id'], attempt=b['attempt'], entered=b['entered'])
        wait_for(b['entered'], activation)  # plan guard acquired; defect row is still held
        time.sleep(0.6)
        check(activation.poll() is None, 'linked-job activation waits for a concurrent defect machine change')
        release(a); finish(changing); refused = finish(activation)
        state = run(action='inspect', week=weeks[17], equipment_id=eid, defect_id=defect_id)
        check('machine' in ' '.join(refused.get('errors', [])).lower()
              and int(state['defect']['equipment_id']) == alt_id and find_job(state, title + ' linked')['status'] == 'Done',
              'a waiting linked job rejects the changed defect machine')
        assert run(action='change_equipment', equipment_id=eid, asset=asset, defect_id=defect_id)['updated']

        a, b = paths(tmp, 'activation-first'), paths(tmp, 'equipment-waits')
        activation = start(action='save', week=weeks[17], equipment_id=eid, title=title + ' linked',
                           job_id=linked['id'], **a)
        wait_for(a['ready'], activation)
        changing = start(action='change_equipment', equipment_id=alt_id, asset=alt_asset, defect_id=defect_id,
                         attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], changing, 'defect machine change waits for linked-job activation')
        release(a); activated = finish(activation); refused = finish(changing)
        state = run(action='inspect', week=weeks[17], equipment_id=eid, defect_id=defect_id)
        check(activated.get('id') == linked['id'] and not refused.get('early_plan') and 'plan now exists' in refused.get('error', '').lower()
              and int(state['defect']['equipment_id']) == eid and find_job(state, title + ' linked')['status'] == 'Planned',
              'the locked current read refuses a machine change after a job was activated')

        mismatch = run(action='save', week=weeks[17], equipment_id=alt_id, title=title + ' linked', job_id=linked['id'])
        duplicate = run(action='save', week=weeks[17], equipment_id=eid, title=title + ' duplicate',
                        source='FromBacklog', defect_id=defect_id)
        state = run(action='inspect', week=weeks[17], equipment_id=eid, defect_id=defect_id)
        check('machine' in ' '.join(mismatch.get('errors', [])).lower()
              and 'another active' in ' '.join(duplicate.get('errors', [])).lower()
              and int(find_job(state, title + ' linked')['equipment_id']) == eid and not find_job(state, title + ' duplicate'),
              'forecast cannot move a linked active job to another machine or create a duplicate')

        # Terminal updates remain available for review. Reactivating after the
        # defect closes, including through legacy inspection sync, must fail.
        assert run(action='save', week=weeks[17], equipment_id=eid, title=title + ' linked',
                   job_id=linked['id'], status='Done').get('id')
        run(action='change_defect_status', defect_id=defect_id, status='Closed')
        refused = run(action='save', week=weeks[17], equipment_id=eid, title=title + ' linked', job_id=linked['id'])
        check('closed or cancelled' in ' '.join(refused.get('errors', [])).lower(),
              'a closed defect cannot acquire a reactivated linked job')
        run(action='change_defect_status', defect_id=defect_id, status='Open')
        inspection_link = run(action='save', week=weeks[16], equipment_id=eid, title=title + ' linked inspection',
                              status='Done', source='FromInspection', defect_id=defect_id)
        assert inspection_link.get('id')
        assert run(action='change_equipment', equipment_id=alt_id, asset=alt_asset, defect_id=defect_id)['updated']
        refused = run(action='status', week=weeks[16], equipment_id=eid)
        state = run(action='inspect', week=weeks[16], equipment_id=eid, defect_id=defect_id)
        check('machine' in refused.get('error', '').lower() and find_job(state, title + ' linked inspection')['status'] == 'Done',
              'legacy inspection sync cannot reactivate a linked job on the wrong machine')

        # Manual entries and bulk generators are also serialized.
        a, b = paths(tmp, 'cell'), paths(tmp, 'cell-pub')
        cell = start(action='cell', week=weeks[9], equipment_id=eid, **a)
        wait_for(a['ready'], cell)
        pub = start(action='publish', week=weeks[9], equipment_id=eid, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], pub, 'manual forecast edits share the publication lock')
        release(a); finish(cell); finish(pub)
        state = run(action='inspect', week=weeks[9], equipment_id=eid)
        check(snapshot_hours(state, eid, weeks[9]) == 3, 'manual forecast hours are frozen in the baseline')

        a, b = paths(tmp, 'generator'), paths(tmp, 'generator-pub')
        generator = start(action='standing', week=weeks[10], equipment_id=eid, **a)
        wait_for(a['ready'], generator, timeout=90)
        pub = start(action='publish', week=weeks[10], equipment_id=eid, attempt=b['attempt'], entered=b['entered'])
        blocked(b['attempt'], b['entered'], pub, 'bulk generation shares the publication lock')
        release(a); generated = finish(generator); finish(pub)
        state = run(action='inspect', week=weeks[10], equipment_id=eid)
        check(generated['placed'] > 0 and snapshot_hours(state, eid, weeks[10], 'standing_hours') > 0,
              'published snapshot includes the committed generated allowance')
        check(assert_frozen(run(action='standing', week=weeks[10], equipment_id=eid))
              and assert_frozen(run(action='inspections', week=weeks[10], equipment_id=eid)),
              'generation cannot change a published week')

        # A legacy inspection job can span weeks; a status sync in its first
        # week must not mutate it if the second week has already been frozen.
        job = run(action='save', week=weeks[11], job_end=weeks[12], equipment_id=eid,
                  title=title + ' inspection', status='Done', source='FromInspection')
        run(action='publish', week=weeks[12], equipment_id=eid)
        status = run(action='status', week=weeks[11], equipment_id=eid)
        state = run(action='inspect', week=weeks[11], equipment_id=eid)
        check(assert_frozen(status) and find_job(state, title + ' inspection')['status'] == 'Done',
              'inspection status sync refuses a job touching another frozen week')

finally:
    for p in procs:
        if p.poll() is None:
            p.kill()
            p.communicate(timeout=15)
    run(action='cleanup', week=weeks[0], end=end_date, defect_id=defect_id)

print(f'{checks} real-database concurrency checks passed.', flush=True)
