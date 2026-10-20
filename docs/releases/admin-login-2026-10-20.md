# Admin login repair, 2026-10-20

The login form could return HTTP 500 for an existing account because Anokii
alpha.13 constructed AuthManager using an obsolete signature. The shared admin
gate also used maintenance authorization during HTTP requests, and the shell
read sealed account identity fields.

The fix belongs to Anokii. Upstream PR #48 fixes current main; PR #49 carries the
focused correction in the published `v0.1.0-alpha.14` maintenance release at
`4fb56033226dad35b5a16aa0aea8f463b27000dc`. The user explicitly authorized this
maintenance release exception. The package's unrelated standalone-distribution
changes on main are outside this app repair.

RHT Circle locks alpha.14 and supplies the framework-owned AuthManager, account
principal factory, audited profile boundary and database login limiter through
its provider. It contains no vendor edits or replacement authentication engine.
The framework remains alpha.305. The dependency audit also identified an existing
ExpressionLanguage advisory; its compatible patch moves from 7.4.18 to 7.4.21.

`AdminWorkflowTest` exercises the real app HTTP kernel and published package:
anonymous inbox protection, login form CSRF token, invalid password rejection,
successful sign-in and next-page redirect, escaped inbox rendering, analytics
rendering, and protection after logout. It uses an isolated database and a
synthetic account. Existing campaign integration tests cover successful contact
conversion storage and exclusion of release checks from campaign reporting.

Deploy through the exact app-main pin in infrastructure main and the existing
RHT Circle promotion script. Its Linux suite and isolated production database
upgrade must pass before the serving container changes. The private operations
receipt records the actual serving image and live verification.

Email delivery remains outside scope. Contact messages are reviewed in the
authenticated inbox.

References: https://github.com/waaseyaa/anokii/pull/48 and
https://github.com/waaseyaa/anokii/pull/49.
