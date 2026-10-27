# ltisource_ltipseudonym - Playwright

`ltipseudonym.spec.js` needs the two-site stack (A = Moodle 4.5 platform, B = Moodle 5.3 tool, `enrol_lti`) described by the
seed file `LTI_SEED` (default `/home/claude/e2e/state/seed.json`); helpers come from `E2E_LIB` (default
`/home/claude/e2e/lib/e2e`). The seeded tool has AGS and NRPS enabled.

The spec writes a policy for the seeded tool on A and

1. launches B as a seeded student and reads `auth_lti_linked_login.sub` on B (pseudonymised, stable, separated by scope and by type),
2. records the documented `login_hint` leak (gate SP-G1),
3. runs B's own scheduled tasks `sync_members` (NRPS) and `sync_grades` / `sync_tool_grades` (AGS) against A. They address A with the
   pseudonym that B stored, through the service proxy (`service.php`) of the plugin. The member is matched, not duplicated, and the
   grade arrives at the real user on A; the web server log shows the proxy served the calls (finding SP-02).

`afterAll` deletes the policy rows. `support/b_grade.php` gives a user on B a grade (run on B through `moodle_cli`).

```bash
# stack up and seeded; from the plugin directory
NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js
```

The config bypasses the sandbox proxy for the test hosts (`*.platform-a.test`, `*.tool-b.test`). Needs `psql` access to both site
databases and read access to `/home/claude/e2e/log/nginx-access.log`. Not part of CI.

Status: executed 2026-10-25, Chromium only: 7 passed, 0 failed.
