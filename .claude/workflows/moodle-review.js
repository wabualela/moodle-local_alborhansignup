export const meta = {
  name: 'moodle-review',
  description: 'Adversarial multi-lens review of a git range of a Moodle plugin; returns verified findings with severity, file:line and fix',
  whenToUse: 'After a phase of work on a Moodle plugin and before tagging a release. args: {base (required), head, moodleroot, repo, context, lenses, votes, maxVerify, standardsAgent}. Read-only.',
  phases: [
    { title: 'Scope', detail: 'changed files, commits, CI legs, supported Moodle branches' },
    { title: 'Review', detail: 'Moodle standards, behaviour/security, test critic, JS/a11y/RTL in parallel' },
    { title: 'Verify', detail: 'skeptics try to refute every finding' },
  ],
}

// Usage examples (the Moodle checkout is only read, never modified):
//   /moodle-review with args {"base": "v1.2.3", "moodleroot": "<dev-checkout>"}
//   /moodle-review with args "v1.2.3..HEAD"
// args.moodleroot is a Moodle code checkout of the target version (the directory that contains public/ for 5.1+).
// Without it, reviewers read core from github.com/moodle/moodle instead.

const USAGE = 'args = { base: "<commit or tag>" (required), head: "HEAD", moodleroot: "<Moodle checkout, read-only>", ' +
  'repo: "<plugin repository, default: current directory>", context: "<site notes: theme, language, RTL, data>", ' +
  'lenses: ["standards", "behaviour", "tests", "frontend"], votes: 1, maxVerify: 40, standardsAgent: "moodle-dev:moodle-reviewer" } ' +
  'or the string "<base>..<head>"'

function text(v) {
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : null
}
function fail(msg) {
  throw new Error(`moodle-review: ${msg}. ${USAGE}`)
}
const REF = /^[A-Za-z0-9._\/@~^{}-]+$/
const PATHLIKE = /^[^\n\r`$;|&<>]+$/

let A = {}
if (typeof args === 'string') {
  const parts = args.trim().split(/\.{2,3}/)
  A = { base: parts[0], head: parts[1] }
} else if (args && typeof args === 'object' && !Array.isArray(args)) {
  A = args
} else if (args !== undefined) {
  fail('args must be an object or a "<base>..<head>" string')
}

const base = text(A.base)
const head = text(A.head) || 'HEAD'
if (!base) fail('args.base is required')
if (!REF.test(base) || !REF.test(head)) fail('base/head must be plain git refs (commit, tag or branch)')
const moodleroot = text(A.moodleroot)
if (moodleroot && !PATHLIKE.test(moodleroot)) fail('args.moodleroot must be a plain path')
const repo = text(A.repo)
if (repo && !PATHLIKE.test(repo)) fail('args.repo must be a plain path')
const context = text(A.context)
const ALL_LENSES = ['standards', 'behaviour', 'tests', 'frontend']
let wanted = ALL_LENSES
if (A.lenses !== undefined) {
  if (!Array.isArray(A.lenses)) fail('args.lenses must be an array')
  wanted = A.lenses.filter(l => ALL_LENSES.includes(l))
  if (!wanted.length) fail(`args.lenses must contain some of ${ALL_LENSES.join(', ')}`)
}
const explicitLenses = A.lenses !== undefined
const votes = Number.isInteger(A.votes) ? Math.min(Math.max(A.votes, 1), 5) : 1
const maxVerify = Number.isInteger(A.maxVerify) && A.maxVerify > 0 ? A.maxVerify : 40
const standardsAgent = A.standardsAgent === '' ? null : (text(A.standardsAgent) || 'moodle-dev:moodle-reviewer')

const REPO = repo ? `the repository at ${repo}` : 'the git repository in your current working directory'
const GIT = repo ? `git -C ${repo}` : 'git'
const RANGE = `${base}..${head}`

const SAFETY = `
SAFETY RULES (breaking any of them fails your task):
- READ-ONLY. Do not edit, create or delete files in the plugin repository or in any Moodle checkout. No git commands that change state (checkout, switch, stash, reset, commit, tag, push, clean); use --no-optional-locks for git in a Moodle checkout.
- Never write to a production site or its database: no SQL clients, no Moodle CLI scripts (admin/cli/*), do not bootstrap config.php or run php against a site's code, and do not read any config.php that holds credentials.
- Never run PHPUnit or Behat init/util scripts (admin/tool/phpunit/cli/*, admin/tool/behat/cli/*): they drop and reinstall tables and modify vendor/.
- HTTP: anonymous GET requests only. Never POST, log in or call web services on a live site, not even as a probe you expect to fail.
- No sudo, no docker, no npm/composer installs inside a Moodle checkout or the repository. Probes and copies go only in your own scratch/temporary directory.
- Never copy secrets, tokens, credentials or personal data into your output.
- Cite evidence as file:line: plugin files relative to the repository root, Moodle files relative to the Moodle root (e.g. public/lib/formslib.php:2794), URLs for web sources.`

const SEVERITY = `
Severity: critical = security hole, data loss or a broken site/page in production; high = fails CI on any leg, wrong behaviour for users, or an accessibility blocker; medium = edge-case bug, robustness or standards problem CI will not catch; low = minor but real.
Report only REAL problems with concrete evidence (no style nits phpcs already enforces, no "consider" suggestions). Use line 0 when a finding has no single line. If you find nothing, return an empty findings list and say what you checked.`

const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          severity: { type: 'string', enum: ['critical', 'high', 'medium', 'low'] },
          title: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          evidence: { type: 'string' },
          failure_scenario: { type: 'string' },
          fix: { type: 'string' },
          confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
        },
        required: ['severity', 'title', 'file', 'line', 'evidence', 'failure_scenario', 'fix', 'confidence'],
      },
    },
    checked: { type: 'array', items: { type: 'string' } },
    notes: { type: 'string' },
  },
  required: ['findings', 'checked', 'notes'],
}

const SCOPE = {
  type: 'object',
  properties: {
    commits: { type: 'array', items: { type: 'string' } },
    files: { type: 'array', items: { type: 'string' } },
    has_php: { type: 'boolean' },
    has_frontend: { type: 'boolean' },
    has_tests: { type: 'boolean' },
    component: { type: 'string' },
    supported: { type: 'string' },
    ci_legs: { type: 'array', items: { type: 'string' } },
    moodle_version: { type: 'string' },
    moodle_tags: { type: 'string' },
    summary: { type: 'string' },
  },
  required: ['commits', 'files', 'has_php', 'has_frontend', 'has_tests', 'component', 'supported', 'ci_legs',
    'moodle_version', 'moodle_tags', 'summary'],
}

const VERDICT = {
  type: 'object',
  properties: {
    verdict: { type: 'string', enum: ['confirmed', 'refuted', 'uncertain'] },
    reasoning: { type: 'string' },
    evidence: { type: 'string' },
    severity: { type: 'string', enum: ['critical', 'high', 'medium', 'low'] },
    fix: { type: 'string' },
  },
  required: ['verdict', 'reasoning', 'evidence', 'severity', 'fix'],
}

// ---------------------------------------------------------------- Scope
phase('Scope')
const scope = await agent(`${SAFETY}

TASK: describe the change set ${RANGE} of the Moodle plugin in ${REPO}, for reviewers. Run:
- '${GIT} log --oneline ${RANGE}' and '${GIT} diff --stat ${RANGE}' (list every changed file path);
- read version.php (component, requires, supported) and the CI configuration (.github/workflows/*.yml or similar) to list the CI legs (Moodle branch / PHP / database);
${moodleroot
    ? `- the Moodle checkout ${moodleroot}: its version (public/version.php for 5.1+, else version.php: $release) and the available release tags ('git -C ${moodleroot} --no-optional-locks tag --list "v*" | tail -n 30'; empty if it is not a git clone).`
    : '- no Moodle checkout was given: set moodle_version and moodle_tags to "".'}
has_frontend = any change under amd/, templates/, styles.css, *.scss, *.css, *.mustache, or vendored JS/CSS. has_tests = any change under tests/. Keep summary to 5 lines.`,
{ label: 'scope', phase: 'Scope', schema: SCOPE, effort: 'low' })

if (!scope) throw new Error('moodle-review: the scope agent returned nothing; check that the range exists')
if (!scope.files.length) {
  log(`No changed files in ${RANGE}; nothing to review.`)
  return { range: RANGE, scope, confirmed: [], uncertain: [], unverified: [], rejected: [], counts: {} }
}

const CORE = moodleroot
  ? `Moodle core source: ${moodleroot} (read-only${scope.moodle_version ? `, ${scope.moodle_version}` : ''}). For another supported branch use 'git -C ${moodleroot} --no-optional-locks show <tag>:<path>'${scope.moodle_tags ? ` (tags available: ${scope.moodle_tags.split(/\s+/).slice(-8).join(' ')})` : ''}.`
  : 'No local Moodle checkout: read core files from https://github.com/moodle/moodle on the matching MOODLE_XXX_STABLE branch (raw.githubusercontent.com).'

const CTX = `${SAFETY}

REVIEW TARGET: the Moodle plugin ${scope.component || ''} in ${REPO}, range ${RANGE}. Read '${GIT} diff ${RANGE}'; use '${GIT} show ${base}:<path>' for the previous behaviour.
Commits:
${scope.commits.slice(0, 40).join('\n')}
Changed files: ${scope.files.slice(0, 200).join(', ')}
Plugin requires/supports: ${scope.supported}. CI legs: ${scope.ci_legs.join('; ') || 'unknown'}.
${CORE}
${context ? `Site context: ${context}` : ''}
Tests run in CI (or a separate dev checkout), never here. Reason from code with file:line evidence; you may use safe local tools in the repository (e.g. dev/check.sh, a jsdom harness) and probes in your scratch directory.
${SEVERITY}`

const LENSES = [
  {
    key: 'standards',
    agentType: standardsAgent,
    prompt: `${CTX}

LENS: Moodle standards and CI gates, beyond what phpcs reports. For every changed file check:
- moodle-plugin-ci gates: phplint; phpcs --max-warnings 0 (CI: moodle via moodle-plugin-ci; dev/check.sh: moodle-extra); phpdoc --max-warnings 0 (every class, method, function and constant documented, @param/@return types match signatures, @package present, test classes documented); validate (version.php fields, lang file sorted, pluginname string, db/*.php formats); savepoints (db/upgrade.php when db/install.xml changes); mustache lint; grunt (eslint on amd/src with 0 warnings, amd/build up to date, stylelint, gherkinlint).
- API availability in EVERY supported branch: classes, hooks, attributes and function signatures used; deprecated or removed APIs; legacy callbacks replaced by Hooks API callbacks (#[replaces_callbacks]); the formats core really parses for db/hooks.php, db/events.php, db/services.php (compare with core examples).
- PHPUnit conventions: file location, namespace and class naming, attributes (CoversClass, DataProvider), resetAfterTest, static data providers.
- Version bump and changelog: changes to db/*.php, lib.php callbacks, new autoloaded classes, lang strings or JS need a version.php bump (hook, callback and string caches are keyed on the version) and a CHANGES.md entry.
- Privacy provider still matches the data stored; vendored code listed in thirdpartylibs.xml with a readme.
If the repository has a local check script (e.g. dev/check.sh), run it without installing anything and report the result in 'checked'.`,
  },
  {
    key: 'behaviour',
    prompt: `${CTX}

LENS: behaviour, security and production risk.
- For each behaviour the range touches, state whether it is unchanged, intentionally changed (per CHANGES.md or the commit messages) or UNINTENTIONALLY changed versus ${base}. Only unintended or harmful changes are findings.
- Trace every core caller of each callback, hook, event observer or web service the change registers or modifies (cite core file:line): which data arrives, whether fields can be missing, whether it also runs in CLI, AJAX, web service, cron, install or upgrade contexts, and whether an exception can escape and break a page for everyone.
- Security (Moodle checklist): require_login and capability checks, sesskey on state changes, PARAM cleaning, output escaping (XSS), SQL placeholders, IDOR, file serving, secrets; and alternative entry points that bypass a form-level rule (web services including ajax/nologin services, other forms, CSV upload, auth sync, admin tools).
- Code that runs on every page or every user update: cost per request, behaviour at scale with legacy data in arbitrary formats.
- Privacy: personal data shown to the wrong viewers, stored or exported unexpectedly.
- Deploy safety: the window between copying files and running the upgrade (new classes, cached hook lists), irreversible data changes, version downgrades.`,
  },
  {
    key: 'tests',
    prompt: `${CTX}

LENS: adversarial test critic. For EVERY new or modified test (PHPUnit and Behat) predict PASS or FAIL on EACH CI leg by tracing the exact core code paths, and say why:
- data generator side effects (events fire, so observers run on generated users), debugging() calls (fail advanced_testcase), configuration a fresh test site lacks, static caches that make a mid-test config change ineffective, 'phpunit --fail-on-warning' (risky tests, tests without assertions, deprecations, non-static data providers), API differences between branches.
- Behat: every step definition exists in every supported branch; JavaScript scenarios only see visible text; selector assumptions; WebDriver quirks (clearing a field, special characters); custom step classes (file and class naming, location).
- Tests that pass for the wrong reason, or assert something the change does not guarantee.
- Changed behaviour without a test, or tests that would not catch a regression of the fix.
Predicted failures are findings of severity high with the exact fix; missing coverage is medium or low.`,
  },
  {
    key: 'frontend',
    prompt: `${CTX}

LENS: JavaScript, accessibility, RTL and languages.
- AMD modules (amd/src): behaviour against the real core and theme JavaScript they interact with: form submit and validation listeners and their order, theme_boost form-display-errors refocusing invalid fields, cancel buttons and skipped client validation, Enter-key submits, dynamic or modal forms that serialise FormData; several instances on one page; init timing; missing elements; per-page cost. amd/build must match amd/src.
- Accessibility (WCAG 2.1 AA): keyboard-only use (no focus traps, Escape, Tab order), accessible names and labels, errors tied to their field and announced, contrast, touch targets; the page still works without JavaScript.
- RTL and languages: styles.css is RTL-flipped and parsed by Moodle (use /*rtl:ignore*/ where needed; third-party CSS that must not be flipped is better linked separately), input in other scripts (e.g. Arabic-Indic digits), hard-coded strings instead of lang strings (core/str, {{#str}}), escaping in Mustache.
- Themes: templates, renderers and JavaScript the site's theme overrides (look under the theme's templates/, classes/output/ and amd/src in the Moodle checkout).
You may run the repository's JS harness if it has one (e.g. dev/jsdom) or write jsdom probes in your scratch directory that load the built module; never modify the repository.`,
  },
]

let lenses = LENSES.filter(l => wanted.includes(l.key))
if (!explicitLenses && !scope.has_frontend) {
  lenses = lenses.filter(l => l.key !== 'frontend')
  log('No JavaScript, CSS or template changes: skipping the frontend lens (pass args.lenses to force it).')
}

async function runLens(lens) {
  const opts = { label: `review:${lens.key}`, phase: 'Review', schema: FINDINGS }
  if (lens.agentType) {
    try {
      const r = await agent(lens.prompt, { ...opts, agentType: lens.agentType })
      if (r) return r
      log(`${lens.agentType} returned no result; rerunning the ${lens.key} lens with the default agent.`)
    } catch (e) {
      log(`Agent type ${lens.agentType} is not available (${String((e && e.message) || e).slice(0, 120)}); using the default agent for the ${lens.key} lens.`)
    }
  }
  return agent(lens.prompt, opts)
}

// ---------------------------------------------------------------- Review
phase('Review')
const lensResults = await parallel(lenses.map(l => () => runLens(l).then(r => (r ? { lens: l.key, ...r } : null))))
const missingLenses = lenses.map(l => l.key).filter(k => !lensResults.find(r => r && r.lens === k))
if (missingLenses.length) log(`Lenses without a result: ${missingLenses.join(', ')}`)

// Deduplicate across lenses: same file and line, and similar titles.
function words(s) {
  return new Set(String(s).toLowerCase().split(/[^a-z0-9_]+/).filter(w => w.length > 3))
}
function similar(a, b) {
  const wa = words(a), wb = words(b)
  if (!wa.size || !wb.size) return false
  let common = 0
  for (const w of wa) if (wb.has(w)) common++
  return common / Math.min(wa.size, wb.size) >= 0.5
}
const RANK = { critical: 0, high: 1, medium: 2, low: 3 }
const merged = []
for (const r of lensResults.filter(Boolean)) {
  for (const f of r.findings) {
    const dup = merged.find(m => m.file === f.file && (m.line === f.line || Math.abs(m.line - f.line) <= 3) && similar(m.title, f.title))
    if (dup) {
      dup.lenses.push(r.lens)
      if (RANK[f.severity] < RANK[dup.severity]) dup.severity = f.severity
      dup.evidence += `\n[${r.lens}] ${f.evidence}`
    } else {
      merged.push({ ...f, lenses: [r.lens] })
    }
  }
}
merged.sort((a, b) => RANK[a.severity] - RANK[b.severity])
merged.forEach((f, i) => { f.id = `R${i + 1}` })
log(`${merged.length} distinct findings from ${lensResults.filter(Boolean).length} lenses.`)

const toVerify = merged.slice(0, maxVerify)
const unverified = merged.slice(maxVerify)
if (unverified.length) log(`Only the ${maxVerify} most severe findings are verified; ${unverified.length} are returned as unverified (raise args.maxVerify).`)

// ---------------------------------------------------------------- Verify
phase('Verify')
const VLENSES = [
  'reproduce it: trace the concrete code path step by step, or run a safe probe in your scratch directory',
  'check every claim about Moodle core or library semantics against the exact source of each supported version',
  'look for something that already prevents the problem (other checks, callers, configuration) and check the fix',
]
const judged = await pipeline(toVerify, f => {
  const n = RANK[f.severity] <= RANK.high ? Math.max(votes, 2) : votes
  return parallel(Array.from({ length: n }, (_, i) => () => agent(`${SAFETY}

You are a skeptical verifier. Angle: ${VLENSES[i % VLENSES.length]}.
A reviewer reported the finding below about the Moodle plugin in ${REPO}, range ${RANGE}. Try to REFUTE it: re-read the cited code yourself (plugin and Moodle core; ${CORE}), check that the claimed path really happens at ${head}, and check that the proposed fix is correct and minimal.
Verdict: 'confirmed' only if the problem is real; 'refuted' if the evidence does not hold up or the problem is already handled (the default when the evidence is weak); 'uncertain' only if deciding needs something you cannot do here, such as running CI. Give the severity you would assign and the fix you would apply.

FINDING:
${JSON.stringify({ id: f.id, severity: f.severity, title: f.title, file: f.file, line: f.line, evidence: f.evidence, failure_scenario: f.failure_scenario, fix: f.fix }, null, 2)}`,
  { label: `verify:${f.id}${n > 1 ? `#${i + 1}` : ''}`, phase: 'Verify', schema: VERDICT })))
    .then(vs => {
      const got = vs.filter(Boolean)
      const yes = got.filter(v => v.verdict === 'confirmed')
      const no = got.filter(v => v.verdict === 'refuted')
      let status = 'uncertain'
      if (!got.length) status = 'unverified'
      else if (yes.length > no.length && yes.length >= Math.ceil(got.length / 2)) status = 'confirmed'
      else if (no.length > yes.length) status = 'rejected'
      const lead = yes[0] || got[0]
      return {
        ...f,
        status,
        severity: status === 'confirmed' && lead ? lead.severity : f.severity,
        fix: status === 'confirmed' && lead && lead.fix ? lead.fix : f.fix,
        verification: got.map(v => ({ verdict: v.verdict, reasoning: v.reasoning, evidence: v.evidence })),
      }
    })
})

const done = judged.filter(Boolean)
const by = s => done.filter(f => f.status === s).sort((a, b) => RANK[a.severity] - RANK[b.severity])
const result = {
  range: RANGE,
  scope: { component: scope.component, files: scope.files.length, ci_legs: scope.ci_legs, summary: scope.summary },
  confirmed: by('confirmed'),
  uncertain: by('uncertain'),
  unverified: [...by('unverified'), ...unverified.map(f => ({ ...f, status: 'unverified' }))],
  rejected: by('rejected').map(f => ({ id: f.id, title: f.title, file: f.file, line: f.line, lenses: f.lenses, verification: f.verification })),
  checked: Object.fromEntries(lensResults.filter(Boolean).map(r => [r.lens, { checked: r.checked, notes: r.notes }])),
}
result.counts = {
  confirmed: result.confirmed.length,
  uncertain: result.uncertain.length,
  unverified: result.unverified.length,
  rejected: result.rejected.length,
}
log(`Confirmed ${result.counts.confirmed}, uncertain ${result.counts.uncertain}, unverified ${result.counts.unverified}, rejected ${result.counts.rejected}.`)
return result
