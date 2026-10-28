/**
 * Two-site E2E for ltisource_ltipseudonym: Site A (Moodle 4.5, platform) launches Site B (Moodle 5.3, tool, enrol_lti).
 *
 * The policy is written on A directly into the plugin's policy table (the admin UI is covered by Behat/PHPUnit).
 * Assertions on B read auth_lti_linked_login.sub, i.e. what the tool really received in the JWT.
 *
 * The reference test (finding SP-02) is the bidirectional one: B's own scheduled tasks call NRPS (sync_members) and AGS
 * (sync_grades) of A with the pseudonymous sub that B stored, through the service proxy of the plugin, and the grade must
 * arrive at the REAL user on A.
 *
 * Status: written against the E2E contract (seed.json); executed locally, see docs/TESTING.md.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
// E2E_HOME: root of the two-site stack (default matches the reference environment).
const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const e2e = require(process.env.E2E_LIB || path.join(E2E_HOME, 'lib/e2e'));

const seed = e2e.loadSeed();
const typeid = seed.lti.platform.type_id;
const issuer = seed.lti.iss;
const POL = 'mdl_ltisource_ltipseudonym_pol';
const MAP = 'mdl_ltisource_ltipseudonym_map';
const AUD = 'mdl_ltisource_ltipseudonym_aud';
const PROXY = '/mod/lti/source/ltipseudonym/service.php/';
const BGRADE = path.join(__dirname, 'support', 'b_grade.php');

e2e.screenshotOnFailure(test);

/** Run a Moodle CLI script on a site ('a' or 'b') and return its output. Throws with the output on failure. */
function cli(site, args) {
  try {
    return execFileSync('bash', ['-c', `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} ${args} 2>&1`],
      { env: process.env, timeout: 300000 }).toString();
  } catch (e) {
    throw new Error(`CLI on ${site} failed: ${args}\n${e.stdout ? e.stdout.toString() : e.message}`);
  }
}

function setPolicy(pseudotype, scopetype, extra = {}) {
  const cols = { discresult: null, ...extra };
  e2e.sqlA(`delete from ${POL} where typeid=${typeid}`);
  const names = ['typeid', 'courseid', 'cmid', 'pseudotype', 'scopetype', 'allowoverride', 'usermodified', 'timecreated',
    'timemodified'].concat(Object.keys(cols).filter((k) => cols[k] !== null));
  const values = [typeid, 0, 0, `'${pseudotype}'`, `'${scopetype}'`, 0, 0, 'extract(epoch from now())::int',
    'extract(epoch from now())::int'].concat(Object.keys(cols).filter((k) => cols[k] !== null).map((k) => `'${cols[k]}'`));
  e2e.sqlA(`insert into ${POL} (${names.join(', ')}) values (${values.join(', ')})`);
}

function bindings() {
  return e2e.sqlB(`select sub from mdl_auth_lti_linked_login where issuer='${issuer}' order by id`)
    .split('\n').filter(Boolean);
}

/** The pseudonym (active mapping) of a local user of A for the seeded tool, in the given scope and of the given type. */
function pseudonymOf(localId, scopetype = 'deployment', ptype = 'random_persisted') {
  return e2e.sqlA(`select token from ${MAP} where userid=${localId} and typeid=${typeid} and status='active'
                   and scopetype='${scopetype}' and ptype='${ptype}' order by id desc limit 1`).trim();
}

/** Launch the seeded activity as a student in a fresh browser context; returns A's local user id and the login request URLs. */
async function launchAs(browser, key) {
  e2e.sqlA(`update mdl_lti set launchcontainer=5 where id=${seed.lti_activity.instance}`);
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const loginRequests = [];
  page.on('request', (req) => {
    if (/\/enrol\/lti\/login\.php/.test(req.url())) { loginRequests.push(req.url()); }
  });
  await e2e.login(page, 'a', key, seed);
  const localId = Number(await page.evaluate(() => window.M.cfg.userId));
  await e2e.openLtiActivity(page, seed);
  await e2e.waitForLaunch(page, new RegExp(`${seed.urls.b.replace(/[.]/g, '\\.')}/course/view\\.php`));
  await ctx.close();
  return { localId, loginRequests };
}

test.describe.serial('Pseudonym: sub received by B', () => {
  test.afterAll(() => {
    e2e.sqlA(`delete from ${POL} where typeid=${typeid}`);
  });

  test('pseudonymised sub is not the local id and is stable on relaunch (the tool has AGS and NRPS)', async ({ browser }) => {
    setPolicy('random_persisted', 'deployment');
    // The stack is shared: other suites launch the same student without a policy, so a binding with the local id may exist
    // already. What counts is that THIS launch created none.
    const before = bindings();
    const first = await launchAs(browser, 'studentA1');
    const sub = pseudonymOf(first.localId);
    // The tool received exactly the pseudonym of the mapping table, never the local id. (The mapping may exist from an
    // earlier run of this spec: the assertions do not depend on the launch being the first one.)
    expect(sub).not.toBe('');
    expect(sub).not.toBe(String(first.localId));
    expect(sub.length).toBeGreaterThanOrEqual(22);
    expect(bindings()).toContain(sub);
    const added = bindings().filter((s) => !before.includes(s));
    expect(added.filter((s) => s === String(first.localId))).toEqual([]);
    const afterFirst = bindings();
    await launchAs(browser, 'studentA1');
    expect(bindings()).toEqual(afterFirst);
    expect(pseudonymOf(first.localId)).toBe(sub);
    const rows = e2e.sqlA(`select count(*) from ${MAP} where userid=${first.localId} and typeid=${typeid} and status='active'
                           and scopetype='deployment' and ptype='random_persisted'`);
    expect(Number(rows)).toBe(1);
  });

  test('a different scope yields a different, non-correlatable sub', async ({ browser }) => {
    setPolicy('random_persisted', 'course');
    const launch = await launchAs(browser, 'studentA1');
    const sub = pseudonymOf(launch.localId, 'course');
    expect(sub).not.toBe('');
    expect(sub).not.toBe(String(launch.localId));
    expect(sub).not.toBe(pseudonymOf(launch.localId, 'deployment'));
    expect(bindings()).toContain(sub);
  });

  test('hmac_secret_v1 differs from random_persisted for the same user and scope', async ({ browser }) => {
    setPolicy('hmac_secret_v1', 'deployment');
    const launch = await launchAs(browser, 'studentA1');
    const sub = pseudonymOf(launch.localId, 'deployment', 'hmac_secret_v1');
    expect(sub).not.toBe('');
    expect(sub).not.toBe(pseudonymOf(launch.localId, 'deployment', 'random_persisted'));
    expect(bindings()).toContain(sub);
  });

  test('documented leak SP-G1: the login request still carries the local id as login_hint', async ({ browser }) => {
    setPolicy('random_persisted', 'deployment');
    const launch = await launchAs(browser, 'studentA1');
    const hints = launch.loginRequests
      .map((u) => new URL(u).searchParams.get('login_hint'))
      .filter((h) => h !== null);
    // Evidence, not a feature: this assertion documents the open gate. It must turn red once core stops leaking.
    expect(hints).toContain(String(launch.localId));
  });
});

test.describe.serial('Pseudonym: the services of the tool work with the pseudonym (SP-02 reference test)', () => {
  let localId;
  let pseudonym;
  let buserid;

  test.afterAll(() => {
    e2e.sqlA(`delete from ${POL} where typeid=${typeid}`);
  });

  test('NRPS: B sync_members matches the members by the pseudonymous sub through the proxy', async ({ browser }) => {
    setPolicy('random_persisted', 'deployment');
    ({ localId } = await launchAs(browser, 'studentA1'));
    pseudonym = pseudonymOf(localId);
    buserid = e2e.sqlB(`select userid from mdl_auth_lti_linked_login where issuer='${issuer}' and sub='${pseudonym}'`).trim();
    expect(buserid).not.toBe('');

    const numericBefore = bindings().filter((s) => /^\d+$/.test(s));
    const countBefore = bindings().length;
    const out = cli('b', "admin/cli/scheduled_task.php --execute='\\enrol_lti\\local\\ltiadvantage\\task\\sync_members'");
    expect(out).not.toMatch(/memberships request failed/i);
    expect(out).toMatch(/\d+ members received/);
    expect(out).toMatch(/Scheduled task complete/);

    const after = bindings();
    // Nobody got a local id as sub, and the learner who launched was matched, not duplicated.
    expect(after.filter((s) => /^\d+$/.test(s))).toEqual(numericBefore);
    expect(after.filter((s) => s === pseudonym).length).toBe(1);
    expect(after.length).toBeGreaterThanOrEqual(countBefore);
    const proxied = e2e.sqlA(`select count(*) from ${MAP} where typeid=${typeid} and status='active' and scopetype='deployment'`);
    expect(Number(proxied)).toBeGreaterThanOrEqual(1);
  });

  test('AGS: the grade B sends with userId = pseudonym arrives at the real user on A', async () => {
    // enrol_lti does not resend a grade equal to the last one it sent, so pick another value.
    const last = Number(e2e.sqlB(`select coalesce(max(lastgrade), 0) from mdl_enrol_lti_users where userid=${buserid}`).trim() || 0);
    let grade = 55 + (Date.now() % 40);
    if (Math.round(last) === grade) {
      grade = grade === 94 ? 55 : grade + 1;
    }
    const started = Math.floor(Date.now() / 1000);
    const bcourse = seed.courses.b.id;
    expect(cli('b', `${BGRADE} --userid=${buserid} --courseid=${bcourse} --grade=${grade}`)).toContain('ok');

    const missBefore = Number(e2e.sqlA(`select count(*) from ${AUD} where action='service_pseudonym_miss'`));
    cli('b', "admin/cli/scheduled_task.php --execute='\\enrol_lti\\local\\ltiadvantage\\task\\sync_grades'");
    const out = cli('b', "admin/cli/adhoc_task.php --execute --classname='\\enrol_lti\\local\\ltiadvantage\\task\\sync_tool_grades'");
    expect(out, out).toMatch(/Success - The grade/);
    expect(out).not.toMatch(/failed to send/i);

    // The grade is stored for the REAL user of A, in a grade item of the activity's course, and for nobody else.
    const rows = e2e.sqlA(`select gg.userid from mdl_grade_grades gg join mdl_grade_items gi on gi.id = gg.itemid
                           where gi.courseid = ${seed.courses.a.id} and gg.finalgrade is not null and gg.timemodified >= ${started - 5}
                           and gi.itemtype in ('mod', 'manual')`).split('\n').filter(Boolean).map(Number);
    expect(rows).toContain(localId);
    expect(rows.filter((id) => id !== localId)).toEqual([]);
    // Nothing was refused as unknown.
    expect(Number(e2e.sqlA(`select count(*) from ${AUD} where action='service_pseudonym_miss'`))).toBe(missBefore);
  });

  test('the proxy, not core, served the calls (web server log of A)', async () => {
    const log = require('fs').readFileSync(path.join(E2E_HOME, 'log/nginx-access.log'), 'utf8');
    const lines = log.split('\n').filter((l) => l.includes(PROXY));
    expect(lines.length).toBeGreaterThan(0);
    expect(lines.some((l) => /scores/.test(l) && /" 200 /.test(l))).toBe(true);
    expect(lines.some((l) => /memberships/.test(l) && /" 200 /.test(l))).toBe(true);
  });
});
