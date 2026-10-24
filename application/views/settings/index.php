<div style="max-width:600px;margin:0 auto;">
  <div class="card">
    <div class="ch"><div><h1 class="ct">System Settings</h1><div class="cs">Workshop configuration</div></div></div>
    <div style="padding:24px;">
      <?= form_open('settings') ?>
      <?php $s = (isset($settings_data) ? $settings_data : []); ?>
      <div class="form-grid cols-1" style="gap:16px;margin-bottom:24px;">
        <div>
          <label class="form-label">Company / Department Name</label>
          <input type="text" name="settings[company_name]" class="form-control" value="<?= (isset($s['company_name']) ? $s['company_name']->setting_value : 'Workshop Management') ?>">
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label">Working Hours Per Day</label>
            <input type="number" name="settings[working_hours_per_day]" class="form-control" value="<?= (isset($s['working_hours_per_day']) ? $s['working_hours_per_day']->setting_value : '8') ?>">
          </div>
          <div>
            <label class="form-label">Work Days Per Month</label>
            <input type="number" name="settings[work_days_per_month]" class="form-control" value="<?= (isset($s['work_days_per_month']) ? $s['work_days_per_month']->setting_value : '26') ?>">
          </div>
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label">Currency</label>
            <?php $site_currency = isset($s['currency']) && $s['currency']->setting_value !== ''
                    ? strtoupper((string)($s['currency']->setting_value)) : wms_site_currency(); ?>
            <select name="settings[currency]" class="form-select">
              <?php foreach (wms_currencies() as $c): ?>
              <option value="<?= $c ?>" <?= $site_currency === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
              <?php if (!in_array($site_currency, wms_currencies(), true)): ?>
              <option value="<?= htmlspecialchars((string)($site_currency)) ?>" selected><?= htmlspecialchars((string)($site_currency)) ?> (current)</option>
              <?php endif; ?>
            </select>
            <div class="cs" style="margin-top:4px;">Used for spend totals and as the default in every currency picker.</div>
          </div>
          <div>
            <label class="form-label">PM Alert (days before due)</label>
            <input type="number" name="settings[pm_alert_days]" class="form-control" value="<?= (isset($s['pm_alert_days']) ? $s['pm_alert_days']->setting_value : '7') ?>">
          </div>
        </div>
      </div>

      <?php
      // ── Weekly report settings — W0.7 ─────────────────────────────
      $rk      = isset($report_keys) ? $report_keys : array();
      $rdef    = isset($settings_defaults) ? $settings_defaults : array();
      $rdesc   = isset($settings_descriptions) ? $settings_descriptions : array();
      $rval    = function ($key, $fallback) use ($s) {
          return isset($s[$key]) && $s[$key]->setting_value !== '' ? $s[$key]->setting_value : $fallback;
      };
      $rlabel  = array(
          'weekly_open_hours'           => 'Weekly Open Hours',
          'daily_open_hours'            => 'Daily Open Hours',
          'default_availability_target' => 'Fallback Availability Target',
          'report_week_start_day'       => 'Report Week Starts On',
          'breakdown_count_mode'        => 'Breakdown Counting',
          'mtbf_zero_failure_mode'      => 'MTBF/MTTR With No Breakdown',
          'pm_meter_stale_days'         => 'PM Meter Staleness (days)',
      );
      if ($rk): ?>
      <div class="ch" style="margin-top:26px;"><div><div class="ct">Weekly Report Settings</div>
        <div class="cs">Used by the weekly report generator — the selected week's name is managed from the report dashboard</div></div></div>
      <div class="form-grid cols-1" style="gap:16px;margin-bottom:24px;">
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $rlabel['weekly_open_hours'] ?></label>
            <input type="number" min="1" name="settings[weekly_open_hours]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('weekly_open_hours', $rdef['weekly_open_hours']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['weekly_open_hours'] ?></div>
          </div>
          <div>
            <label class="form-label"><?= $rlabel['daily_open_hours'] ?></label>
            <input type="number" min="1" max="24" name="settings[daily_open_hours]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('daily_open_hours', $rdef['daily_open_hours']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['daily_open_hours'] ?></div>
          </div>
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $rlabel['default_availability_target'] ?></label>
            <input type="text" name="settings[default_availability_target]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('default_availability_target', $rdef['default_availability_target']))) ?>">
            <div class="cs" style="margin-top:4px;">0.88 or 88 = 88 % — <?= $rdesc['default_availability_target'] ?></div>
          </div>
          <div>
            <label class="form-label"><?= $rlabel['report_week_start_day'] ?></label>
            <select name="settings[report_week_start_day]" class="form-select">
              <?php $cur_dow = (int)$rval('report_week_start_day', $rdef['report_week_start_day']);
                    foreach (array(1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday') as $n=>$day): ?>
              <option value="<?= $n ?>" <?= $cur_dow === $n ? 'selected' : '' ?>><?= $day ?></option>
              <?php endforeach; ?>
            </select>
            <div class="cs" style="margin-top:4px;">The week ends six days later</div>
          </div>
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $rlabel['breakdown_count_mode'] ?></label>
            <select name="settings[breakdown_count_mode]" class="form-select">
              <?php $cur_mode = $rval('breakdown_count_mode', $rdef['breakdown_count_mode']); ?>
              <option value="start_in_period" <?= $cur_mode === 'start_in_period' ? 'selected' : '' ?>>Count events that start inside the week</option>
              <option value="overlapping" <?= $cur_mode === 'overlapping' ? 'selected' : '' ?>>Count every event overlapping the week</option>
            </select>
            <div class="cs" style="margin-top:4px;">Number of Breakdowns — days-down and hours-down are unaffected</div>
          </div>
          <div>
            <label class="form-label"><?= $rlabel['mtbf_zero_failure_mode'] ?></label>
            <select name="settings[mtbf_zero_failure_mode]" class="form-select">
              <?php $cur_mtbf = $rval('mtbf_zero_failure_mode', $rdef['mtbf_zero_failure_mode']); ?>
              <option value="dash" <?= $cur_mtbf === 'dash' ? 'selected' : '' ?>>Show &ldquo;&mdash;&rdquo; (dash)</option>
              <option value="uptime" <?= $cur_mtbf === 'uptime' ? 'selected' : '' ?>>Show the uptime value</option>
            </select>
            <div class="cs" style="margin-top:4px;">A machine with no breakdown in the period</div>
          </div>
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $rlabel['pm_meter_stale_days'] ?></label>
            <input type="number" min="0" name="settings[pm_meter_stale_days]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('pm_meter_stale_days', $rdef['pm_meter_stale_days']))) ?>">
            <div class="cs" style="margin-top:4px;">0 = off — <?= $rdesc['pm_meter_stale_days'] ?></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php
      /* ── Forecast planning — D31 ─────────────────
       * The planned standing and unplanned allowances drive most of any
       * planned week, so they belong to an authorised planner rather than to a
       * constant in the model. */
      $fk    = isset($forecast_keys) ? $forecast_keys : array();
      $flabel = array(
          'standing_allowance_default_hours'   => 'Standing Allowance (h/day)',
          'standing_allowance_hours'           => 'Standing Allowance by Equipment Type',
          'unplanned_allowance_mode'           => 'Unplanned Allowance',
          'unplanned_allowance_default_hours'  => 'Unplanned Allowance (h/day)',
          'unplanned_allowance_lookback_weeks' => 'Unplanned Lookback (weeks)',
          'forecast_show_excel_reference'      => 'Historical Excel Reference Columns',
      );
      if ($fk): ?>
      <div class="ch" style="margin-top:26px;"><div><div class="ct">Forecast Planning</div>
        <div class="cs">Planned downtime allowances — see <em>Forecast &rsaquo; Planned Allowances</em> for their effect on a week</div></div></div>
      <div class="form-grid cols-1" style="gap:16px;margin-bottom:24px;">
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $flabel['standing_allowance_default_hours'] ?></label>
            <input type="number" min="0" max="24" step="0.5" name="settings[standing_allowance_default_hours]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('standing_allowance_default_hours', $rdef['standing_allowance_default_hours']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['standing_allowance_default_hours'] ?></div>
          </div>
          <div>
            <label class="form-label"><?= $flabel['unplanned_allowance_mode'] ?></label>
            <select name="settings[unplanned_allowance_mode]" class="form-select">
              <?php $cur_ua = $rval('unplanned_allowance_mode', $rdef['unplanned_allowance_mode']); ?>
              <option value="flat"    <?= $cur_ua === 'flat'    ? 'selected' : '' ?>>Flat — one figure for every machine</option>
              <option value="derived" <?= $cur_ua === 'derived' ? 'selected' : '' ?>>Derived — each machine's own loss rate</option>
            </select>
            <div class="cs" style="margin-top:4px;"><?= $rdesc['unplanned_allowance_mode'] ?></div>
          </div>
        </div>
        <div class="form-grid cols-2">
          <div>
            <label class="form-label"><?= $flabel['unplanned_allowance_default_hours'] ?></label>
            <input type="number" min="0" max="24" step="0.5" name="settings[unplanned_allowance_default_hours]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('unplanned_allowance_default_hours', $rdef['unplanned_allowance_default_hours']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['unplanned_allowance_default_hours'] ?></div>
          </div>
          <div>
            <label class="form-label"><?= $flabel['unplanned_allowance_lookback_weeks'] ?></label>
            <input type="number" min="1" max="104" name="settings[unplanned_allowance_lookback_weeks]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('unplanned_allowance_lookback_weeks', $rdef['unplanned_allowance_lookback_weeks']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['unplanned_allowance_lookback_weeks'] ?></div>
          </div>
        </div>
        <div>
          <label class="form-label"><?= $flabel['standing_allowance_hours'] ?></label>
          <textarea name="settings[standing_allowance_hours]" class="form-control" rows="3" spellcheck="false"
                    style="font-family:ui-monospace,monospace;font-size:12px;"><?= htmlspecialchars((string)($rval('standing_allowance_hours', $rdef['standing_allowance_hours']))) ?></textarea>
          <div class="cs" style="margin-top:4px;"><?= $rdesc['standing_allowance_hours'] ?></div>
        </div>
        <div>
          <label class="form-label"><?= $flabel['forecast_show_excel_reference'] ?></label>
          <select name="settings[forecast_show_excel_reference]" class="form-select" style="max-width:340px;">
            <?php $cur_ref = $rval('forecast_show_excel_reference', $rdef['forecast_show_excel_reference']); ?>
            <option value="1" <?= $cur_ref === '1' ? 'selected' : '' ?>>Show them (labelled as reference)</option>
            <option value="0" <?= $cur_ref === '0' ? 'selected' : '' ?>>System figures only</option>
          </select>
          <div class="cs" style="margin-top:4px;"><?= $rdesc['forecast_show_excel_reference'] ?></div>
        </div>
      </div>
      <?php endif; ?>

      <?php /* ── Team / leave — the values the Team screens read ── */ ?>
      <div class="ch" style="margin-top:26px;"><div><div class="ct">Team &amp; Leave</div>
        <div class="cs">Certification alerting and the leave balances shown on the Team screens</div></div></div>
      <div class="form-grid cols-1" style="gap:16px;margin-bottom:24px;">
        <div class="form-grid cols-2">
          <div>
            <label class="form-label">Certification Expiry Alert (days)</label>
            <input type="number" min="1" max="365" name="settings[cert_alert_days]" class="form-control"
                   value="<?= htmlspecialchars((string)($rval('cert_alert_days', $rdef['cert_alert_days']))) ?>">
            <div class="cs" style="margin-top:4px;"><?= $rdesc['cert_alert_days'] ?></div>
          </div>
        </div>
        <div>
          <label class="form-label">Leave Entitlements (days per year, JSON)</label>
          <textarea name="settings[leave_entitlements]" class="form-control" rows="3" spellcheck="false"
                    style="font-family:ui-monospace,monospace;font-size:12px;"><?= htmlspecialchars((string)($rval('leave_entitlements', $rdef['leave_entitlements']))) ?></textarea>
          <div class="cs" style="margin-top:4px;"><?= $rdesc['leave_entitlements'] ?></div>
        </div>
      </div>

      <?php /* ── Individual Attendance Sheet (AML Signatories) ── */ ?>
      <div class="ch" style="margin-top:26px;"><div><div class="ct">Individual Attendance Sheet (AML Signatories)</div>
        <div class="cs">Names and titles printed in the three-column approval block at the bottom of exported PDF &amp; Excel attendance sheets</div></div></div>
      <div class="form-grid cols-2" style="gap:16px;margin-bottom:24px;">
        <div>
          <label class="form-label">Prepared By — Name</label>
          <input type="text" name="settings[timesheet_prepared_by]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_prepared_by', $rdef['timesheet_prepared_by']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_prepared_by'] ?></div>
        </div>
        <div>
          <label class="form-label">Prepared By — Title</label>
          <input type="text" name="settings[timesheet_prepared_title]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_prepared_title', $rdef['timesheet_prepared_title']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_prepared_title'] ?></div>
        </div>
        <div>
          <label class="form-label">Checked By — Heading</label>
          <input type="text" name="settings[timesheet_checked_by]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_checked_by', $rdef['timesheet_checked_by']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_checked_by'] ?></div>
        </div>
        <div>
          <label class="form-label">Checked By — Subtitle (leave blank for signature line)</label>
          <input type="text" name="settings[timesheet_checked_title]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_checked_title', $rdef['timesheet_checked_title']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_checked_title'] ?></div>
        </div>
        <div>
          <label class="form-label">Approved By — Name</label>
          <input type="text" name="settings[timesheet_approved_by]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_approved_by', $rdef['timesheet_approved_by']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_approved_by'] ?></div>
        </div>
        <div>
          <label class="form-label">Approved By — Title</label>
          <input type="text" name="settings[timesheet_approved_title]" class="form-control" maxlength="120"
                 value="<?= htmlspecialchars((string)($rval('timesheet_approved_title', $rdef['timesheet_approved_title']))) ?>">
          <div class="cs" style="margin-top:4px;"><?= $rdesc['timesheet_approved_title'] ?></div>
        </div>
      </div>

      <div class="flex ai-c gap-10" style="justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">Save Settings</button>
      </div>
      <?= form_close() ?>
    </div>
  </div>
</div>
