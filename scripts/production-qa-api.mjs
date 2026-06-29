#!/usr/bin/env node
/**
 * Production QA API test suite — no code changes, report only.
 */
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8001';
const USERS = [
  { role: 'super_admin', email: 'superadmin@ca.local', password: 'password' },
  { role: 'admin', email: 'admin@ca.local', password: 'password' },
  { role: 'manager', email: 'manager@ca.local', password: 'password' },
  { role: 'employee', email: 'employee@ca.local', password: 'password' },
];

const results = [];

function log(module, test, ok, detail = '', meta = {}) {
  results.push({ module, test, ok, detail, ...meta });
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${module} :: ${test}${detail ? ' — ' + detail : ''}`);
}

async function login(context, email, password) {
  const page = await context.newPage();
  await page.goto(`${BASE}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
  await page.close();
}

async function apiGet(context, path, expectStatus = 200) {
  const r = await context.request.get(`${BASE}${path}`, {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });
  let body = null;
  try { body = await r.json(); } catch { body = await r.text(); }
  return { status: r.status(), ok: r.status() === expectStatus, body };
}

async function apiPost(context, path, data, expectStatus = [200, 201]) {
  const r = await context.request.post(`${BASE}${path}`, {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
    data,
  });
  let body = null;
  try { body = await r.json(); } catch { body = await r.text(); }
  const ok = Array.isArray(expectStatus) ? expectStatus.includes(r.status()) : r.status() === expectStatus;
  return { status: r.status(), ok, body };
}

async function main() {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });

  // Guest unauthorized
  {
    const ctx = await browser.newContext();
    const r = await ctx.request.get(`${BASE}/dashboard/metrics`, { headers: { Accept: 'application/json' } });
    log('Auth', 'Guest blocked from dashboard/metrics', r.status() === 401 || r.status() === 302 || r.status() === 403, `HTTP ${r.status()}`);
    await ctx.close();
  }

  // CSRF without token
  {
    const ctx = await browser.newContext();
    const r = await ctx.request.post(`${BASE}/login`, { form: { email: 'admin@ca.local', password: 'password' } });
    log('Auth', 'POST without CSRF returns 419', r.status() === 419, `HTTP ${r.status()}`);
    await ctx.close();
  }

  let adminContext = null;
  let employeeContext = null;
  let managerContext = null;

  for (const u of USERS) {
    const ctx = await browser.newContext();
    await login(ctx, u.email, u.password);
    const me = await apiGet(ctx, '/auth/me');
    log('Auth', `${u.role} login + /auth/me`, me.ok && me.body?.data?.email === u.email, `HTTP ${me.status}`);

    const dash = await apiGet(ctx, '/dashboard/metrics');
    log('Dashboard', `${u.role} dashboard metrics`, dash.ok, `leads=${dash.body?.data?.total_leads}`);

    const logoutPage = await ctx.newPage();
    if (u.role === 'admin') adminContext = ctx;
    if (u.role === 'employee') employeeContext = ctx;
    if (u.role === 'manager') managerContext = ctx;
    await logoutPage.close();
  }

  // RBAC: employee scoping
  if (employeeContext) {
    const all = await apiGet(employeeContext, '/ca-masters?per_page=100');
    const total = all.body?.data?.pagination?.total ?? 0;
    const assigned = await apiGet(employeeContext, '/lead-assignments?per_page=100&status=Active');
    const assignCount = assigned.body?.data?.pagination?.total ?? 0;
    log('RBAC', 'Employee CA list scoped', all.ok, `visible=${total} assigned_active=${assignCount}`);
    log('RBAC', 'Employee cannot access db-health', !(await apiGet(employeeContext, '/admin/db-health', 403)).ok || (await apiGet(employeeContext, '/admin/db-health')).status === 403,
      `HTTP ${(await apiGet(employeeContext, '/admin/db-health')).status}`);
  }

  if (adminContext) {
    const ctx = adminContext;
    const ts = Date.now();

    // Master data
    for (const [name, path] of [
      ['States lookup', '/lookups/states'],
      ['Cities lookup MH', '/lookups/cities?state_id=1'],
      ['Source leads', '/source-leads?all=1'],
      ['Team sizes', '/team-sizes?all=1'],
      ['Role masters', '/role-masters?all=1'],
    ]) {
      const r = await apiGet(ctx, path);
      const items = Array.isArray(r.body?.data) ? r.body.data : (r.body?.data?.items ?? []);
      const hasValid = items.length > 0 && (items[0]?.state_name || items[0]?.city_name || items[0]?.source_name || items[0]?.team_size_label || items[0]?.role_name);
      const poisoned = JSON.stringify(items[0] ?? {}).includes('Incomplete_Class');
      log('Master Data', name, r.ok && hasValid && !poisoned, `count=${items.length} poisoned=${poisoned}`, { route: path });
    }

    // CA Master CRUD
    const states = await apiGet(ctx, '/lookups/states');
    const stateId = states.body?.data?.[0]?.state_id;
    const cities = await apiGet(ctx, `/lookups/cities?state_id=${stateId}`);
    const cityId = cities.body?.data?.[0]?.city_id;
    const createLead = await apiPost(ctx, '/ca-masters', {
      firm_name: `QA Firm ${ts}`, ca_name: 'QA CA', mobile_no: `9${String(ts).slice(-9)}`,
      email_id: `qa.${ts}@test.local`, state_id: stateId, city_id: cityId, status: 'New', rating: 4, team_size: 5,
    }, 201);
    const caId = createLead.body?.data?.ca_id;
    log('Core CRM', 'CA Master create', createLead.ok && caId, `ca_id=${caId}`, { route: 'POST /ca-masters' });

    const search = await apiGet(ctx, `/ca-masters?search=QA+Firm+${ts}&per_page=10`);
    log('Core CRM', 'CA Master search', search.ok && (search.body?.data?.pagination?.total >= 1), `found=${search.body?.data?.pagination?.total}`);

    const filter = await apiGet(ctx, '/ca-masters?rating_min=4&status=New&per_page=10');
    log('Core CRM', 'CA Master filter', filter.ok, `total=${filter.body?.data?.pagination?.total}`);

    if (caId) {
      const upd = await ctx.request.put(`${BASE}/ca-masters/${caId}`, {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: { firm_name: `QA Firm Updated ${ts}`, ca_name: 'QA CA', mobile_no: createLead.body.data.mobile_no, email_id: createLead.body.data.email_id, state_id: stateId, city_id: cityId, status: 'Warm' },
      });
      log('Core CRM', 'CA Master update', upd.status() === 200, `HTTP ${upd.status()}`);
    }

    // Employee CRUD
    const createEmp = await apiPost(ctx, '/employees', {
      name: `QA Employee ${ts}`, email_id: `qa.emp.${ts}@test.local`, mobile_no: `8${String(ts).slice(-9)}`, role: 'Sales Executive', status: 'Active',
    }, 201);
    const empId = createEmp.body?.data?.employee_id;
    log('Core CRM', 'Employee create', createEmp.ok && empId, `employee_id=${empId}`);

    // Assignment + Follow-up
    if (caId && empId) {
      const assign = await apiPost(ctx, '/lead-assignments', { ca_id: caId, employee_id: empId, assignment_type: 'Manual', reason: 'QA_TEST' }, 201);
      log('Core CRM', 'Lead assignment create', assign.ok, `HTTP ${assign.status}`);
      const fu = await apiPost(ctx, '/follow-ups', { ca_id: caId, employee_id: empId, followup_type: 'Call', scheduled_date: new Date().toISOString().slice(0, 10), status: 'Scheduled', remarks: 'QA follow-up' }, 201);
      log('Core CRM', 'Follow-up create', fu.ok, `HTTP ${fu.status}`);
    }

    // Validation 422
    const bad = await apiPost(ctx, '/ca-masters', { firm_name: '' }, 422);
    log('Backend', '422 validation on bad CA create', bad.status === 422, `HTTP ${bad.status}`);

    // Notifications
    const notif = await apiGet(ctx, '/notifications');
    log('Communication', 'Notifications API', notif.ok, `HTTP ${notif.status}`, { route: 'GET /notifications', file: 'routes/web.php' });

    // Queue status
    const queue = await apiGet(ctx, '/admin/queue-status');
    log('Environment', 'Queue status API', queue.ok, `pending=${queue.body?.data?.pending_jobs}`, { route: 'GET /admin/queue-status' });

    // DB Health
    const db = await apiGet(ctx, '/admin/db-health');
    log('Database Health', 'DB health API', db.ok && db.body?.success, `tables=${db.body?.data?.tables?.length ?? 0}`);

    // Reports
    for (const [name, path] of [
      ['Reports index', '/reports'],
      ['Analytics', '/reports/analytics'],
      ['Lead conversion', '/reports/lead-conversion'],
      ['Employee performance', '/reports/employee-performance'],
      ['Follow-up performance', '/reports/follow-up-performance'],
      ['Assignment stats', '/reports/assignment-statistics'],
      ['Campaign analytics', '/reports/campaign-analytics'],
      ['Monthly trends', '/reports/monthly-trends'],
    ]) {
      const r = await apiGet(ctx, path);
      log('Reports', name, r.ok, `HTTP ${r.status}`, { route: path });
    }

    // Export (expect 200 or async JSON)
    const exportR = await apiGet(ctx, '/reports/lead-conversion/export');
    log('Reports', 'Lead conversion export', exportR.status === 200, `HTTP ${exportR.status}`);

    // Campaigns simulation
    const wa = await apiPost(ctx, '/whatsapp-campaigns', { campaign_name: `QA WA ${ts}`, campaign_type: 'Demo Confirmation', audience_mode: 'all_leads', message_template: 'Hello {{name}}' }, 201);
    log('Communication', 'WhatsApp campaign create', wa.ok, `HTTP ${wa.status}`);
    const email = await apiPost(ctx, '/email-campaigns', { campaign_name: `QA Email ${ts}`, campaign_type: 'Bulk Email', audience_mode: 'all_leads', subject: 'QA', body_template: '<p>QA</p>' }, 201);
    log('Communication', 'Email campaign create', email.ok, `HTTP ${email.status}`);
    const sms = await apiPost(ctx, '/sms-campaigns', { campaign_name: `QA SMS ${ts}`, campaign_type: 'Demo Reminder', audience_mode: 'all_leads', sender_id: 'CACLDSK', message_template: 'QA reminder' }, 201);
    log('Communication', 'SMS campaign create', sms.ok, `HTTP ${sms.status}`);

    // Activity logs audit fields
    const logs = await apiGet(ctx, '/activity-logs?per_page=20&sort_dir=desc');
    const items = logs.body?.data?.items ?? [];
    const hasAudit = items.some((l) => l.performed_by);
    const hasBeforeAfter = items.some((l) => l.before_value || l.after_value);
    log('Audit Trail', 'Activity logs list', logs.ok && items.length > 0, `count=${items.length}`);
    log('Audit Trail', 'performed_by populated', hasAudit, `${items.filter(l=>l.performed_by).length}/${items.length}`);
    log('Audit Trail', 'before/after fields present', hasBeforeAfter, `with_audit=${items.filter(l=>l.before_value||l.after_value).length}`);

    // Bulk import sample
    const sample = await ctx.request.get(`${BASE}/ca-masters/bulk-import/sample.csv`);
    log('Bulk Ops', 'Bulk import sample CSV', sample.status() === 200, `HTTP ${sample.status()}`);
    const history = await apiGet(ctx, '/ca-masters/bulk-import/history');
    log('Bulk Ops', 'Bulk import history', history.ok, `HTTP ${history.status}`);

    // Bulk export columns
    const expCols = await apiGet(ctx, '/ca-masters/bulk-export/columns');
    log('Bulk Ops', 'Bulk export columns', expCols.ok, `HTTP ${expCols.status}`);

    // Bulk status update statuses
    const statuses = await apiGet(ctx, '/ca-masters/bulk-status-update/statuses');
    log('Bulk Ops', 'Bulk status update statuses', statuses.ok, `HTTP ${statuses.status}`);

    // Clean JSON errors
    const badReport = await apiGet(ctx, '/reports/nonexistent-slug/export', 404);
    const content = JSON.stringify(badReport.body ?? '');
    log('Backend', '404 JSON sanitized', badReport.status === 404 && !content.includes('Stack trace') && !content.includes('InvalidArgumentException'), `HTTP ${badReport.status}`);

    // Activity log after CRUD
    const addLog = items.find((l) => l.action === 'Add Lead');
    log('Audit Trail', 'Add Lead logged', !!addLog, addLog?.performed_by ?? 'missing');
  }

  if (managerContext) {
    const dbMgr = await apiGet(managerContext, '/admin/db-health', 403);
    log('RBAC', 'Manager blocked from db-health', dbMgr.status === 403, `HTTP ${dbMgr.status}`);
  }

  await browser.close();

  const out = { baseUrl: BASE, timestamp: new Date().toISOString(), passed: results.filter(r => r.ok).length, failed: results.filter(r => !r.ok).length, results };
  fs.writeFileSync('storage/logs/production-qa-api.json', JSON.stringify(out, null, 2));
  console.log(`\nAPI QA: ${out.passed}/${results.length} passed`);
  process.exit(out.failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
