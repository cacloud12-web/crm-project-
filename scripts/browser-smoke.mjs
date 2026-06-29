#!/usr/bin/env node
/**
 * Live browser smoke test for CA Cloud Desk CRM (manager demo readiness).
 * Usage: node scripts/browser-smoke.mjs [baseUrl]
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = process.argv[2] || 'http://127.0.0.1:8001';
const EMAIL = 'manager@ca.local';
const PASSWORD = 'password';

const checks = [];
const consoleIssues = [];
const networkFailures = [];

function record(name, ok, detail = '') {
  checks.push({ name, ok, detail });
  const mark = ok ? 'PASS' : 'FAIL';
  console.log(`[${mark}] ${name}${detail ? ' — ' + detail : ''}`);
}

async function waitForApi(page, urlPart, timeout = 15000) {
  return page.waitForResponse(
    (r) => r.url().includes(urlPart) && r.status() >= 200 && r.status() < 400,
    { timeout },
  );
}

async function main() {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const context = await browser.newContext();
  const page = await context.newPage();

  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error') {
      consoleIssues.push({ type, text: msg.text(), location: msg.location() });
    }
  });

  page.on('pageerror', (err) => {
    consoleIssues.push({ type: 'pageerror', text: err.message });
  });

  page.on('response', (response) => {
    const url = response.url();
    if (url.startsWith(BASE) && response.status() >= 400 && !url.includes('/favicon')) {
      networkFailures.push({ status: response.status(), url });
    }
  });

  try {
    // Login page
    const loginResp = await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    record('Login page loads', loginResp?.ok() === true, `HTTP ${loginResp?.status()}`);
    await page.waitForSelector('#email');

    // Login
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    await Promise.all([
      page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
    record('Manager login', !page.url().includes('/login'), page.url());

    const spaPages = [
      { name: 'Dashboard', path: '/', api: '/dashboard/metrics', selector: '#page-container' },
      { name: 'CA Master', path: '/ca-masters', api: '/ca-masters', selector: '#page-container' },
      { name: 'Leads listing', path: '/leads', api: '/ca-masters', selector: '#page-container' },
      { name: 'Assignment', path: '/assignment', api: '/lead-assignments', selector: '#page-container' },
      { name: 'Follow-up', path: '/followups', api: '/follow-ups', selector: '#page-container' },
      { name: 'Bulk Import', path: '/bulk', api: '/ca-masters/bulk-operations/history', selector: '#page-container' },
      { name: 'Communication hub', path: '/communication', api: null, selector: '#page-container' },
      { name: 'WhatsApp campaigns', path: '/whatsapp', api: '/whatsapp-campaigns', selector: '#page-container' },
      { name: 'Email campaigns', path: '/email', api: '/email-campaigns', selector: '#page-container' },
      { name: 'SMS campaigns', path: '/sms', api: '/sms-campaigns', selector: '#page-container' },
      { name: 'Reports', path: '/reports', api: '/reports', selector: '#page-container' },
      { name: 'Analytics', path: '/analytics', api: '/reports/analytics', selector: '#page-container' },
      { name: 'Activity Logs', path: '/activity', api: '/activity-logs', selector: '#page-container' },
    ];

    for (const spa of spaPages) {
      const responses = [];
      const handler = (r) => {
        if (spa.api && r.url().includes(spa.api) && r.request().method() === 'GET') {
          responses.push(r);
        }
      };
      page.on('response', handler);

      const nav = await page.goto(`${BASE}${spa.path}`, { waitUntil: 'domcontentloaded', timeout: 20000 });
      const pageOk = nav?.ok() === true;
      let apiOk = true;
      let apiDetail = '';

      if (spa.api) {
        try {
          await waitForApi(page, spa.api, 12000);
          const apiResp = responses.find((r) => r.url().includes(spa.api));
          if (apiResp) {
            apiOk = apiResp.status() >= 200 && apiResp.status() < 400;
            apiDetail = `API ${apiResp.status()}`;
          } else {
            apiOk = false;
            apiDetail = 'API not called';
          }
        } catch {
          apiOk = false;
          apiDetail = 'API timeout';
        }
      }

      const selectorOk = (await page.locator(spa.selector).count()) > 0;
      record(
        spa.name,
        pageOk && selectorOk && apiOk,
        `page ${nav?.status()} · ${apiDetail || 'no API check'} · shell ${selectorOk ? 'ok' : 'missing'}`,
      );

      page.off('response', handler);
      await page.waitForTimeout(400);
    }

    // Employee API (employees page may route to JSON resource — verify via assignment tab)
    const empResp = await page.request.get(`${BASE}/employees?per_page=5`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const empJson = await empResp.json().catch(() => ({}));
    const empCount = empJson?.data?.pagination?.total ?? empJson?.data?.items?.length ?? 0;
    record('Employee listing API', empResp.ok() && empCount > 0, `${empCount} employees`);

    // Manager demo leads visible (identified by demo lead email prefix)
    const leadsResp = await page.request.get(`${BASE}/ca-masters?search=manager.demo.lead&per_page=10`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const leadsJson = await leadsResp.json().catch(() => ({}));
    const demoLeads = leadsJson?.data?.pagination?.total ?? 0;
    record('Manager demo CA Masters', leadsResp.ok() && demoLeads >= 5, `${demoLeads} demo leads`);

    // Assignments
    const assignResp = await page.request.get(`${BASE}/lead-assignments?per_page=5`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const assignJson = await assignResp.json().catch(() => ({}));
    const assignTotal = assignJson?.data?.pagination?.total ?? 0;
    record('Lead assignments', assignResp.ok() && assignTotal > 0, `${assignTotal} assignments`);

    // Follow-ups
    const fuResp = await page.request.get(`${BASE}/follow-ups?per_page=5`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const fuJson = await fuResp.json().catch(() => ({}));
    const fuTotal = fuJson?.data?.pagination?.total ?? 0;
    record('Follow-ups', fuResp.ok() && fuTotal > 0, `${fuTotal} follow-ups`);

    // Bulk import sample download
    const sampleResp = await page.request.get(`${BASE}/ca-masters/bulk-import/sample.csv`);
    record('Bulk Import sample CSV', sampleResp.ok(), `HTTP ${sampleResp.status()}`);

    // DB health (admin only — separate session)
    const adminCtx = await browser.newContext();
    const adminPage = await adminCtx.newPage();
    await adminPage.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await adminPage.fill('#email', 'admin@ca.local');
    await adminPage.fill('#password', PASSWORD);
    await Promise.all([
      adminPage.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
      adminPage.click('button[type="submit"]'),
    ]);
    const dbPage = await adminPage.goto(`${BASE}/admin/database-health`, { waitUntil: 'domcontentloaded' });
    record('Database Health page (admin)', dbPage?.ok() === true, `HTTP ${dbPage?.status()}`);
    const dbResp = await adminPage.request.get(`${BASE}/admin/db-health`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const dbJson = await dbResp.json().catch(() => ({}));
    record(
      'Database Health API',
      dbResp.ok() && dbJson?.success === true,
      dbJson?.data?.database?.name || `HTTP ${dbResp.status()}`,
    );
    await adminCtx.close();
  } catch (err) {
    record('Smoke test runner', false, err.message);
  } finally {
    await browser.close();
  }

  const criticalConsole = consoleIssues.filter(
    (c) => !/favicon|Failed to load resource.*404|lucide|403.*Forbidden|bulk-status-update|database-health/i.test(c.text || ''),
  );
  record(
    'Browser console (no critical errors)',
    criticalConsole.length === 0,
    criticalConsole.length ? `${criticalConsole.length} error(s)` : 'clean',
  );

  const criticalNetwork = networkFailures.filter((n) => {
    if (/\/favicon|fonts\.googleapis|fonts\.gstatic/.test(n.url)) return false;
    if (n.status === 403 && /admin\/database-health|bulk-status-update\/statuses/.test(n.url)) return false;
    return n.status >= 400;
  });
  record(
    'Network (no 4xx/5xx on app routes)',
    criticalNetwork.length === 0,
    criticalNetwork.length ? `${criticalNetwork.length} failure(s)` : 'clean',
  );

  const passed = checks.filter((c) => c.ok).length;
  const failed = checks.filter((c) => !c.ok).length;

  const report = {
    baseUrl: BASE,
    user: EMAIL,
    timestamp: new Date().toISOString(),
    passed,
    failed,
    total: checks.length,
    checks,
    consoleIssues: criticalConsole,
    networkFailures: criticalNetwork,
  };

  const outPath = path.join(process.cwd(), 'storage', 'logs', 'browser-smoke-report.json');
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(report, null, 2));

  console.log('\n--- Summary ---');
  console.log(`Passed: ${passed}/${checks.length}`);
  if (criticalConsole.length) {
    console.log('Console errors:');
    criticalConsole.slice(0, 5).forEach((c) => console.log('  -', c.text));
  }
  if (criticalNetwork.length) {
    console.log('Network failures:');
    criticalNetwork.slice(0, 5).forEach((n) => console.log(`  - ${n.status} ${n.url}`));
  }
  console.log(`Report: ${outPath}`);

  process.exit(failed > 0 ? 1 : 0);
}

main();
