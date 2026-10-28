/**
 * Protocol capture of ltisource_ltipseudonym on the two-site stack (finding SP-02): what a tool really receives.
 *
 * Both sites act as LTI 1.3 PLATFORM in turn: A (Moodle 4.5) and B (Moodle 5.3). The tool is played by Playwright: its URLs
 * live on https://capture.tool-c.test, which the browser never reaches because every request to that host is intercepted.
 * The capture tool answers the OIDC login initiation like a real tool (redirect to the platform's auth.php), receives the
 * signed id token, gets a real access token from the platform's token.php (client credentials, RS256 key pair generated
 * here) and calls NRPS and AGS through the URLs of the id token, plus the core URL directly (bypass attempt).
 * LTI 1.1: the OAuth 1 form post of the launch is captured the same way.
 *
 * Every value is REDACTED into a class before it is stored; the result goes to tests/fixtures/disclosure_trace_e2e.json
 * (LTIPSEUDONYM_E2E_OUT overrides the path) and into docs/DISCLOSURE_TRACE.md through the PHPUnit report generator.
 *
 * Shared stack: run it under the stack lock, it creates its own users, registrations and activities (prefix pscap) on both
 * sites and removes them again in afterAll:
 *   flock /tmp/e2e_stack.lock env NODE_EXTRA_CA_CERTS=/home/claude/e2e/pki/ca.crt NODE_PATH=/home/claude/e2e/node_modules \
 *     /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js disclosure_capture.spec.js
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
const { test, expect, request: pwrequest } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const e2e = require(process.env.E2E_LIB || path.join(E2E_HOME, 'lib/e2e'));
const seed = e2e.loadSeed();
const SUPPORT = path.join(__dirname, 'support', 'capture_site.php');
const OUT = process.env.LTIPSEUDONYM_E2E_OUT || path.join(__dirname, '..', 'fixtures', 'disclosure_trace_e2e.json');
const CAPTURE = 'https://capture.tool-c.test';
const PROXY = '/mod/lti/source/ltipseudonym/service.php';
const CORE = '/mod/lti/services.php';
const NRPS = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';
const SCORE = 'application/vnd.ims.lis.v1.score+json';
const RESULTS = 'application/vnd.ims.lis.v2.resultcontainer+json';
const SCOPES = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly ' +
  'https://purl.imsglobal.org/spec/lti-ags/scope/score https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
const CLAIM = {
  nrps: 'https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice',
  ags: 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint',
  custom: 'https://purl.imsglobal.org/spec/lti/claim/custom',
  bo: 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome',
  ext: 'https://purl.imsglobal.org/spec/lti/claim/ext',
  lis: 'https://purl.imsglobal.org/spec/lti/claim/lis',
  ret: 'https://purl.imsglobal.org/spec/lti/claim/launch_presentation',
  type: 'https://purl.imsglobal.org/spec/lti/claim/message_type',
};

const keys = crypto.generateKeyPairSync('rsa', {
  modulusLength: 2048,
  publicKeyEncoding: { type: 'spki', format: 'pem' },
  privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
});
const keyfile = path.join(os.tmpdir(), `pscap-${process.pid}.pem`);
fs.writeFileSync(keyfile, keys.publicKey);

const results = { date: new Date().toISOString().slice(0, 10), platforms: [], cases: [] };

e2e.screenshotOnFailure(test);

/** Run the support script on a site and parse its JSON answer. */
function cli(site, args) {
  try {
    const out = execFileSync('bash', ['-c', `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} ${SUPPORT} ${args} 2>&1`],
      { env: process.env, timeout: 300000 }).toString().trim().split('\n');
    return JSON.parse(out[out.length - 1]);
  } catch (e) {
    throw new Error(`support script on ${site} failed: ${args}\n${e.stdout ? e.stdout.toString() : e.message}`);
  }
}

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

/** Decode a JWT payload (no verification: we look at what is sent). */
function decode(jwt) {
  return JSON.parse(Buffer.from(jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString());
}

/** Classify one value against the identity of a user (redaction: the value itself is never stored). */
function classify(site, value, user) {
  if (value === undefined) { return 'absent'; }
  if (value === null) { return 'null'; }
  const s = String(value);
  if (s === '') { return 'empty'; }
  if (s === String(user.id)) { return 'local_user_id'; }
  if (s === user.email) { return 'email'; }
  if (s === user.username) { return 'username'; }
  if (s === user.idnumber) { return 'idnumber'; }
  if ([user.firstname, user.lastname, `${user.firstname} ${user.lastname}`].includes(s)) { return 'name'; }
  if (/^[A-Za-z0-9_-]{22,64}$/.test(s)) {
    const row = cli(site, `--lookup='${s}'`);
    if (row.exists) {
      return row.userid === user.id ? `pseudonym (${row.scopetype})` : 'pseudonym of another person';
    }
  }
  try {
    const json = JSON.parse(s);
    if (json && json.data && json.data.userid !== undefined) {
      return String(json.data.userid) === String(user.id) ? 'contains_local_user_id' : 'contains_other_user_id';
    }
  } catch (e) { /* not JSON */ }
  if (s.includes('sesskey=')) { return 'contains_session_key'; }
  if (s.includes(PROXY)) { return 'service_url_proxy'; }
  if (s.includes(CORE)) { return 'service_url_core'; }
  return 'no_user_identifier';
}

/**
 * Open a platform URL as a user and capture what reaches the tool host.
 * Returns { login, idtoken, lti11, refused, other }.
 */
async function capture(browser, setup, site, userkey, url) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const seen = { login: null, idtoken: null, lti11: null, refused: null, other: [] };
  await context.route(`${CAPTURE}/**`, async (route) => {
    const req = route.request();
    const u = new URL(req.url());
    const form = Object.fromEntries(new URLSearchParams(req.postData() || u.search.slice(1)));
    if (u.pathname === '/login') {
      seen.login = form;
      const q = new URLSearchParams({
        scope: 'openid', response_type: 'id_token', response_mode: 'form_post', prompt: 'none',
        client_id: form.client_id, redirect_uri: `${CAPTURE}/launch`, login_hint: form.login_hint,
        lti_message_hint: form.lti_message_hint || '', state: 'state-pscap', nonce: `nonce-${Date.now()}`,
      });
      return route.fulfill({ status: 302, headers: { location: `${setup.auth_url}?${q}` } });
    }
    if (u.pathname === '/launch') {
      seen.idtoken = form.id_token || null;
      return route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>captured</body></html>' });
    }
    if (u.pathname === '/lti11') {
      seen.lti11 = form;
      return route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>captured</body></html>' });
    }
    seen.other.push(u.pathname);
    return route.fulfill({ status: 404, body: '' });
  });
  const user = setup.users[userkey];
  await e2e.login(page, site, { username: user.username, password: user.password }, seed);
  await page.goto(url);
  const end = Date.now() + 45000;
  while (Date.now() < end && !seen.idtoken && !seen.lti11) {
    const alert = page.locator('.alert-danger:visible, .errorbox:visible').first();
    if (await alert.count().catch(() => 0)) {
      seen.refused = (await alert.innerText().catch(() => '')).trim().replace(/\s+/g, ' ');
      break;
    }
    await page.waitForTimeout(300);
  }
  await context.close();
  return seen;
}

/** A client-credentials access token of the capture tool from the platform's token endpoint. */
async function accessToken(api, setup) {
  const now = Math.floor(Date.now() / 1000);
  const head = b64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const body = b64url(JSON.stringify({
    iss: setup.lti13.clientid, sub: setup.lti13.clientid, aud: setup.token_url, iat: now, exp: now + 300,
    jti: crypto.randomUUID(),
  }));
  const signature = b64url(crypto.sign('RSA-SHA256', Buffer.from(`${head}.${body}`), keys.privateKey));
  const response = await api.post(setup.token_url, {
    form: {
      grant_type: 'client_credentials',
      client_assertion_type: 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
      client_assertion: `${head}.${body}.${signature}`,
      scope: SCOPES,
    },
  });
  const json = await response.json();
  expect(json.access_token, JSON.stringify(json)).toBeTruthy();
  return json.access_token;
}

/** The learner's member of a roster, classified (null when not found). */
function rosterMember(site, json, user) {
  for (const member of (json && json.members) || []) {
    const cls = classify(site, member.user_id, user);
    if (cls === 'local_user_id' || cls.startsWith('pseudonym (')) {
      return {
        user_id: cls,
        name: classify(site, member.name, user),
        email: classify(site, member.email, user),
        username: classify(site, member.ext_user_username, user),
        idnumber: classify(site, member.lis_person_sourcedid, user),
      };
    }
  }
  return null;
}

/** Services of the capture tool after a launch: NRPS (with/without rlid, paged, core URL), AGS score/results, guesses. */
async function services(site, setup, claims) {
  const api = await pwrequest.newContext();
  const token = await accessToken(api, setup);
  const auth = { Authorization: `Bearer ${token}` };
  const learner = setup.users.learner;
  const out = {};
  const nrpsurl = claims[CLAIM.nrps].context_memberships_url;
  const rlid = setup.lti13.instance;
  const get = async (url, accept) => {
    const r = await api.get(url, { headers: { ...auth, Accept: accept } });
    let json = null;
    try { json = await r.json(); } catch (e) { json = null; }
    return { status: r.status(), json, headers: r.headers(), text: json ? JSON.stringify(json) : '' };
  };
  const plain = await get(nrpsurl, NRPS);
  const withrlid = await get(`${nrpsurl}${nrpsurl.includes('?') ? '&' : '?'}rlid=${rlid}`, NRPS);
  const core = nrpsurl.replace(PROXY, CORE);
  const direct = await get(`${core}${core.includes('?') ? '&' : '?'}rlid=${rlid}`, NRPS);
  out.nrps = { endpoint: classify(site, nrpsurl, learner), status: plain.status, statusWithRlid: withrlid.status };
  out.member = rosterMember(site, withrlid.json, learner);
  out.directStatus = direct.status;
  out.directMember = rosterMember(site, direct.json, learner);
  // Member fields, not a substring search (the role URI ...membership#Learner would match the last name "Learner").
  const personal = [learner.firstname, learner.lastname, `${learner.firstname} ${learner.lastname}`, learner.email];
  out.directHasNames = ((direct.json && direct.json.members) || []).some((m) =>
    [m.name, m.given_name, m.family_name, m.email].some((v) => v && personal.includes(v)));
  out.directHasLocalId = direct.text.includes(`"user_id":"${learner.id}"`);
  // Paging: limit=1, follow the Link header.
  let next = `${nrpsurl}${nrpsurl.includes('?') ? '&' : '?'}rlid=${rlid}&limit=1`;
  const pages = [];
  for (let i = 0; next && i < 10; i++) {
    const page = await get(next, NRPS);
    pages.push({ status: page.status, members: ((page.json && page.json.members) || []).length });
    const link = page.headers.link || '';
    const m = /<([^>]+)>;\s*rel="next"/.exec(link);
    next = m ? m[1] : null;
    if (next) { pages[pages.length - 1].nextOn = next.includes(PROXY) ? 'proxy' : (next.includes(CORE) ? 'core' : 'other'); }
  }
  out.paging = pages;

  const ags = claims[CLAIM.ags] || {};
  if (ags.lineitem) {
    const li = new URL(ags.lineitem);
    const scores = `${li.origin}${li.pathname}/scores${li.search}`;
    const resultsurl = `${li.origin}${li.pathname}/results${li.search}`;
    const score = (userId, given) => ({
      userId, scoreGiven: given, scoreMaximum: 100, gradingProgress: 'FullyGraded', activityProgress: 'Completed',
      timestamp: new Date(Date.now() + 60000 + given * 1000).toISOString(),
    });
    const given = 40 + (Date.now() % 50);
    const post = await api.post(scores, { headers: { ...auth, 'Content-Type': SCORE }, data: JSON.stringify(score(claims.sub, given)) });
    out.scoreStatus = post.status();
    out.gradeStored = cli(site, `--grade --cmid=${setup.lti13.cmid} --userid=${learner.id}`).grade === given;
    const res = await get(resultsurl, RESULTS);
    out.resultsStatus = res.status;
    out.resultsUserId = Array.isArray(res.json) && res.json[0] ? classify(site, res.json[0].userId, learner) : 'none';
    const mate = setup.users.mate;
    const guess = await api.post(scores, { headers: { ...auth, 'Content-Type': SCORE }, data: JSON.stringify(score(String(mate.id), 7)) });
    out.guessStatus = guess.status();
    const guesscore = await api.post(scores.replace(PROXY, CORE), { headers: { ...auth, 'Content-Type': SCORE },
      data: JSON.stringify(score(String(mate.id), 8)) });
    out.guessCoreStatus = guesscore.status();
    out.mateGrade = cli(site, `--grade --cmid=${setup.lti13.cmid} --userid=${mate.id}`).grade;
  }
  await api.dispose();
  return out;
}

/** Record a redacted case of the report. */
function record(platform, kase, data) {
  results.cases.push({ platform, case: kase, ...data });
}

const PLATFORMS = [
  { site: 'a', course: () => seed.courses.a.id },
  { site: 'b', course: () => seed.courses.b.id },
];

const BLANK = { discname: 'blank', discemail: 'blank', discusername: 'blank', discsourcedid: 'blank' };

for (const platform of PLATFORMS) {
  test.describe.serial(`Protocol capture, platform ${platform.site}`, () => {
    let setup;
    let label;

    test.beforeAll(() => {
      setup = cli(platform.site, `--setup --course=${platform.course()} --publickey=${keyfile}`);
      label = `${platform.site.toUpperCase()} (Moodle ${setup.release.split(' ')[0]})`;
      results.platforms.push({ site: platform.site.toUpperCase(), moodle: setup.release.split(' ')[0] });
      cli(platform.site, `--policy='{}'`);
    });

    test.afterAll(() => {
      cli(platform.site, '--cleanup');
    });

    const launchUrl = () => `${seed.urls[platform.site]}/mod/lti/launch.php?id=${setup.lti13.cmid}`;

    const lti13 = async (browser, kase, policy, expectations) => {
      cli(platform.site, `--policy='${JSON.stringify(policy)}'`);
      cli(platform.site, `--cleargrades --cmid=${setup.lti13.cmid}`);
      const seen = await capture(browser, setup, platform.site, 'learner', launchUrl());
      const learner = setup.users.learner;
      if (expectations.refused) {
        expect(seen.login, 'nothing may reach the tool').toBeNull();
        expect(seen.idtoken).toBeNull();
        expect(seen.refused || '').toMatch(/refused|abgelehnt/i);
        record(label, kase, { login_hint: 'not sent', lti_message_hint: 'not sent', sub: 'not sent', names: 'not sent',
          note: 'refused by the request guard before the OIDC login initiation; no request reached the tool' });
        return null;
      }
      expect(seen.login, `login initiation reached the tool: ${seen.refused || ''}`).not.toBeNull();
      expect(seen.idtoken, `id token reached the tool: ${seen.refused || ''}`).not.toBeNull();
      const claims = decode(seen.idtoken);
      const hint = JSON.parse(seen.login.lti_message_hint || '{}');
      const data = {
        login_hint: classify(platform.site, seen.login.login_hint, learner),
        lti_message_hint: `cmid: ${String(hint.cmid) === String(setup.lti13.cmid) ? 'activity id' : classify(platform.site, hint.cmid, learner)}` +
          `, launchid: ${/^ltilaunch\d+_\d+$/.test(hint.launchid || '') ? 'instance id + random' : 'other'}`,
        sub: classify(platform.site, claims.sub, learner),
        names: `${classify(platform.site, claims.name, learner)} / ${classify(platform.site, claims.email, learner)}`,
        custom_userid: classify(platform.site, (claims[CLAIM.custom] || {}).userid, learner),
        result_sourcedid: classify(platform.site, (claims[CLAIM.bo] || {}).lis_result_sourcedid, learner),
        return_url: classify(platform.site, (claims[CLAIM.ret] || {}).return_url, learner),
      };
      // The OIDC login_hint is the open core gate SP-G1: always the local id.
      expect(data.login_hint).toBe('local_user_id');
      expect(data.sub).toBe(expectations.sub);
      const svc = await services(platform.site, setup, claims);
      data.nrps_user_id = svc.member ? `${svc.member.user_id}, name ${svc.member.name}, email ${svc.member.email}` :
        `HTTP ${svc.nrps.statusWithRlid}`;
      data.nrps_direct_core = `HTTP ${svc.directStatus}, ` + (svc.directMember ? `user_id ${svc.directMember.user_id}` : 'no member') +
        `, names ${svc.directHasNames ? 'present' : 'absent'}`;
      data.ags = `score HTTP ${svc.scoreStatus}, grade ${svc.gradeStored ? 'stored for the launching person' : 'NOT stored'}; ` +
        `guessed local id HTTP ${svc.guessStatus} / core URL HTTP ${svc.guessCoreStatus}, other person's grade ` +
        `${svc.mateGrade === null ? 'untouched' : 'CHANGED'}`;
      data.note = `NRPS ${svc.nrps.endpoint}, without rlid HTTP ${svc.nrps.status}; paging ${svc.paging.length} page(s), ` +
        `next links on ${[...new Set(svc.paging.map((p) => p.nextOn).filter(Boolean))].join('+') || 'n/a'}; results userId ` +
        `${svc.resultsUserId}; custom userid ${data.custom_userid}; result id ${data.result_sourcedid}; return URL ${data.return_url}`;
      record(label, kase, data);

      expect(svc.scoreStatus).toBe(200);
      expect(svc.gradeStored).toBe(true);
      expect(svc.mateGrade, 'a guessed local id must never grade another person under a pseudonymising policy')
        .toBe(expectations.pseudonymising ? null : svc.mateGrade);
      if (expectations.pseudonymising) {
        expect(svc.guessStatus).toBe(404);
        expect(svc.guessCoreStatus, 'the core URL is answered by the proxy').toBe(404);
        expect(svc.member && svc.member.user_id).toBe(expectations.sub);
        expect(svc.directHasLocalId, 'no local id through the core URL').toBe(false);
      }
      if (expectations.blank) {
        expect(data.names).toBe('empty / empty');
        expect(svc.member.name).toBe('absent');
        expect(svc.directHasNames, 'no names through the core URL').toBe(false);
      }
      if (expectations.cm) {
        expect(svc.nrps.status, 'activity scope: the roster needs rlid').toBe(400);
      }
      return claims;
    };

    test('no policy: the tool receives the local id everywhere (baseline)', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, no policy', {}, { sub: 'local_user_id', pseudonymising: false });
    });

    test('random_persisted, deployment scope, pass', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, random_persisted, deployment, pass',
        { pseudotype: 'random_persisted', scopetype: 'deployment' }, { sub: 'pseudonym (deployment)', pseudonymising: true });
    });

    test('random_persisted, course scope, personal data blank', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, random_persisted, course, blank',
        { pseudotype: 'random_persisted', scopetype: 'course', ...BLANK },
        { sub: 'pseudonym (course)', pseudonymising: true, blank: true });
    });

    test('random_persisted, activity scope (roster needs rlid)', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, random_persisted, cm, blank',
        { pseudotype: 'random_persisted', scopetype: 'cm', ...BLANK },
        { sub: 'pseudonym (cm)', pseudonymising: true, blank: true, cm: true });
    });

    test('hmac_secret_v1, site scope', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, hmac_secret_v1, site, pass',
        // The site scope is chosen at the site level (a tool level row cannot weaken the default deployment scope).
        { site: { pseudotype: 'hmac_secret_v1', scopetype: 'site' } }, { sub: 'pseudonym (site)', pseudonymising: true });
    });

    test('remove while the tool shares names: refused before anything reaches the tool', async ({ browser }) => {
      await lti13(browser, 'LTI 1.3, random_persisted, remove (tool shares names)',
        { pseudotype: 'random_persisted', scopetype: 'deployment', discname: 'remove' }, { refused: true });
    });

    test('deep linking request of the teacher', async ({ browser }) => {
      cli(platform.site, `--policy='${JSON.stringify({ pseudotype: 'random_persisted', scopetype: 'course', ...BLANK })}'`);
      const url = `${seed.urls[platform.site]}/mod/lti/contentitem.php?id=${setup.lti13.typeid}&course=${setup.courseid}&title=x`;
      const seen = await capture(browser, setup, platform.site, 'teacher', url);
      expect(seen.idtoken, seen.refused || '').not.toBeNull();
      const teacher = setup.users.teacher;
      const claims = decode(seen.idtoken);
      expect(claims[CLAIM.type]).toBe('LtiDeepLinkingRequest');
      const data = {
        login_hint: classify(platform.site, seen.login.login_hint, teacher),
        lti_message_hint: 'launchid only (no activity yet)',
        sub: classify(platform.site, claims.sub, teacher),
        names: `${classify(platform.site, claims.name, teacher)} / ${classify(platform.site, claims.email, teacher)}`,
        note: 'deep linking: activity scope falls back to course scope (no activity exists yet)',
      };
      expect(data.login_hint).toBe('local_user_id');
      expect(data.sub).toBe('pseudonym (course)');
      expect(data.names).toBe('empty / empty');
      record(label, 'LTI 1.3 deep linking, random_persisted, course, blank', data);
    });

    const lti11 = async (browser, kase, policy, setting, refused) => {
      cli(platform.site, `--policy='${JSON.stringify(policy)}'`);
      cli(platform.site, `--setting=legacyprotocol=${setting}`);
      const url = `${seed.urls[platform.site]}/mod/lti/launch.php?id=${setup.lti11.cmid}`;
      const seen = await capture(browser, setup, platform.site, 'learner', url);
      cli(platform.site, '--setting=legacyprotocol=pass');
      const learner = setup.users.learner;
      if (refused) {
        expect(seen.lti11).toBeNull();
        expect(seen.refused || '').toMatch(/refused|abgelehnt/i);
        record(label, kase, { login_hint: 'none in LTI 1.1', sub: 'not sent', names: 'not sent',
          note: 'refused by the callback before the form post; nothing reached the tool' });
        return;
      }
      expect(seen.lti11, seen.refused || '').not.toBeNull();
      const form = seen.lti11;
      const data = {
        login_hint: 'none in LTI 1.1',
        lti_message_hint: 'none in LTI 1.1',
        sub: `user_id ${classify(platform.site, form.user_id, learner)}`,
        names: `${classify(platform.site, form.lis_person_name_full, learner)} / ` +
          `${classify(platform.site, form.lis_person_contact_email_primary, learner)}`,
        note: `lis_result_sourcedid ${classify(platform.site, form.lis_result_sourcedid, learner)}, custom_userid ` +
          `${classify(platform.site, form.custom_userid, learner)}, ext_user_username ` +
          `${classify(platform.site, form.ext_user_username, learner)}; LTI 1.x is not covered by the policies`,
      };
      expect(form.user_id).toBe(String(learner.id));
      record(label, kase, data);
    };

    test('LTI 1.1: no policy', async ({ browser }) => {
      await lti11(browser, 'LTI 1.1, no policy', {}, 'pass', false);
    });

    test('LTI 1.1: site policy active, setting pass (not covered)', async ({ browser }) => {
      await lti11(browser, 'LTI 1.1, site policy, setting pass',
        { site: { pseudotype: 'random_persisted', scopetype: 'site', discname: 'blank' } }, 'pass', false);
    });

    test('LTI 1.1: site policy active, setting refuse', async ({ browser }) => {
      await lti11(browser, 'LTI 1.1, site policy, setting refuse',
        { site: { pseudotype: 'random_persisted', scopetype: 'site' } }, 'refuse', true);
    });
  });
}

test.afterAll(() => {
  fs.rmSync(keyfile, { force: true });
  if (results.cases.length) {
    fs.writeFileSync(OUT, `${JSON.stringify(results, null, 2)}\n`);
  }
});
