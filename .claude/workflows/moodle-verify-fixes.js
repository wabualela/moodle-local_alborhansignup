export const meta = {
  name: 'moodle-verify-fixes',
  description: 'Re-check a list of review findings against a fix commit of a Moodle plugin and look for regressions the fixes introduced',
  whenToUse: 'After fixing the findings of /moodle-review (or any review). args: {findings (required), commit, base, moodleroot, context, votes}. Read-only.',
  phases: [
    { title: 'Verify', detail: 'one verifier per finding, with a challenger for fixes of severe findings' },
    { title: 'Regressions', detail: 'review of the fix diff for new problems' },
  ],
}

// Usage examples (the Moodle checkout is only read, never modified):
//   /moodle-verify-fixes with args {"findings": <the "confirmed" list returned by /moodle-review>, "commit": "HEAD", "base": "<commit reviewed>", "moodleroot": "<dev-checkout>"}
//   /moodle-verify-fixes with args {"findings": ["F1: focus trap when the field is invalid", "F2: legacy value submitted without country code"]}
// args.findings may be an array (objects or strings), the whole /moodle-review result, or a newline-separated string.

const USAGE = 'args = { findings: [ {id, title, file, line, fix} | "<finding text>" ] (required), commit: "HEAD", ' +
  'base: "<commit the findings were made against>", moodleroot: "<Moodle checkout, read-only>", ' +
  'repo: "<plugin repository, default: current directory>", context: "<site notes>", votes: 1, regressions: true }'

function text(v) {
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : null
}
function fail(msg) {
  throw new Error(`moodle-verify-fixes: ${msg}. ${USAGE}`)
}
const REF = /^[A-Za-z0-9._\/@~^{}-]+$/
const PATHLIKE = /^[^\n\r`$;|&<>]+$/

if (!args || typeof args !== 'object' || Array.isArray(args)) {
  if (Array.isArray(args)) fail('pass the list as args.findings, not as args itself')
  fail('args must be an object')
}
const A = args

let raw = A.findings
if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
  // The result of /moodle-review: verify confirmed and uncertain findings.
  raw = [...(raw.confirmed || []), ...(raw.uncertain || []), ...(raw.findings || [])]
}
if (typeof raw === 'string') raw = raw.split('\n').map(s => s.trim()).filter(Boolean)
if (!Array.isArray(raw) || !raw.length) fail('args.findings must be a non-empty list')

const findings = raw.map((f, i) => {
  if (typeof f === 'string') return { id: `F${i + 1}`, text: f }
  if (f && typeof f === 'object') {
    const id = text(f.id) || `F${i + 1}`
    const { verification, status, lenses, ...rest } = f
    return { id, text: JSON.stringify(rest, null, 2), severity: text(f.severity) }
  }
  return fail(`args.findings[${i}] must be a string or an object`)
})

const commit = text(A.commit) || 'HEAD'
const base = text(A.base)
if (!REF.test(commit) || (base && !REF.test(base))) fail('commit/base must be plain git refs')
const moodleroot = text(A.moodleroot)
if (moodleroot && !PATHLIKE.test(moodleroot)) fail('args.moodleroot must be a plain path')
const repo = text(A.repo)
if (repo && !PATHLIKE.test(repo)) fail('args.repo must be a plain path')
const context = text(A.context)
const votes = Number.isInteger(A.votes) ? Math.min(Math.max(A.votes, 1), 3) : 1
const regressions = A.regressions !== false

const MAX = 60
let list = findings
if (list.length > MAX) {
  log(`Only the first ${MAX} of ${list.length} findings are verified.`)
  list = list.slice(0, MAX)
}

const REPO = repo ? `the plugin repository at ${repo}` : 'the plugin repository in your current working directory'
const GIT = repo ? `git -C ${repo}` : 'git'
const DIFF = base ? `'${GIT} diff ${base}..${commit}'` : `'${GIT} log -p -5 ${commit}' (no base commit was given)`
const CORE = moodleroot
  ? `Moodle core source: ${moodleroot} (read-only). Other branches: 'git -C ${moodleroot} --no-optional-locks show <tag>:<path>'.`
  : 'No local Moodle checkout: read core files from https://github.com/moodle/moodle on the matching MOODLE_XXX_STABLE branch.'

const SAFETY = `
SAFETY RULES (breaking any of them fails your task):
- READ-ONLY. Do not edit, create or delete files in the plugin repository or in any Moodle checkout. No git commands that change state; inspect other commits with 'git show <commit>:<path>' instead of checking them out.
- Never write to a production site or its database: no SQL clients, no Moodle CLI scripts (admin/cli/*), do not bootstrap config.php or run php against a site's code, and do not read any config.php that holds credentials.
- Never run PHPUnit or Behat init/util scripts (admin/tool/phpunit/cli/*, admin/tool/behat/cli/*).
- HTTP: anonymous GET requests only; never POST, log in or call web services on a live site.
- No sudo, no docker, no installs inside a Moodle checkout or the repository. Probes (php on copied classes, jsdom on the built AMD module, the repository's own harnesses such as dev/jsdom) run from your scratch/temporary directory or read the repository without writing to it.
- Never copy secrets, tokens, credentials or personal data into your output.
- Cite evidence as file:line (plugin files relative to the repository root, Moodle files relative to the Moodle root) and quote probe output.`

const CTX = `${SAFETY}

Plugin: ${REPO}, fix commit ${commit}. The fix diff: ${DIFF}.
${CORE}
${context ? `Site context: ${context}` : ''}`

const STATUS = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['fixed', 'partially_fixed', 'not_fixed', 'regression', 'cannot_verify'] },
    evidence: { type: 'string' },
    probe: { type: 'string' },
    remaining_work: { type: 'string' },
  },
  required: ['status', 'evidence', 'probe', 'remaining_work'],
}

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
          fix: { type: 'string' },
        },
        required: ['severity', 'title', 'file', 'line', 'evidence', 'fix'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['findings', 'notes'],
}

const severe = f => f.severity === 'critical' || f.severity === 'high'

function verifyAll() {
  return pipeline(
    list,
    f => agent(`${CTX}

TASK: decide whether this finding is fixed at ${commit}. Re-read the code at ${commit} and, where the finding came with a probe or a failure scenario, re-run that scenario (or an equivalent probe against the current build). 'fixed' needs evidence that the failure scenario no longer happens; 'partially_fixed' if some cases remain; 'not_fixed' if it still happens; 'regression' if the fix broke something else; 'cannot_verify' only if it needs something you cannot do here (e.g. CI or a real browser), saying what a human should check.

FINDING ${f.id}:
${f.text}`,
    { label: `verify:${f.id}`, phase: 'Verify', schema: STATUS }),
    (res, f) => {
      if (!res) return null
      const challenge = res.status === 'fixed' && (votes > 1 || severe(f))
      if (!challenge) return { id: f.id, finding: f.text, ...res }
      return agent(`${CTX}

TASK: a verifier claims the finding below is FIXED at ${commit}. Try to show it is NOT: look for inputs, pages, branches (each supported Moodle version), browsers/keyboard paths or call sites the fix misses, and check the verifier's evidence. Answer 'fixed' only if you cannot break it.

FINDING ${f.id}:
${f.text}

VERIFIER'S EVIDENCE:
${res.evidence}
${res.probe ? `PROBE: ${res.probe}` : ''}`,
      { label: `challenge:${f.id}`, phase: 'Verify', schema: STATUS })
        .then(ch => ({
          id: f.id,
          finding: f.text,
          ...res,
          status: ch && ch.status !== 'fixed' && ch.status !== 'cannot_verify' ? 'disputed' : res.status,
          challenge: ch,
        }))
    },
  )
}

function regressionReview() {
  if (!regressions) return Promise.resolve(null)
  return agent(`${CTX}

TASK: review the fix diff for NEW problems the fixes introduced (bugs, behaviour changes, broken tests on any CI leg, accessibility or RTL regressions, security). The original findings are listed only for context; do not re-verify them. Report only real problems with evidence; return an empty list if there are none.

ORIGINAL FINDINGS:
${list.map(f => `${f.id}: ${f.text.slice(0, 400)}`).join('\n')}`,
  { label: 'regressions', phase: 'Regressions', schema: FINDINGS })
}

phase('Verify')
const [verified, regress] = await parallel([() => verifyAll(), () => regressionReview()])
const results = (verified || []).filter(Boolean)
const lost = list.map(f => f.id).filter(id => !results.find(r => r.id === id))
if (lost.length) log(`No verdict for: ${lost.join(', ')}`)

const counts = {}
for (const r of results) counts[r.status] = (counts[r.status] || 0) + 1
log(Object.entries(counts).map(([k, v]) => `${k}: ${v}`).join(', ') || 'no verdicts')

return {
  commit,
  base: base || null,
  results,
  missing: lost,
  regressions: regress ? regress.findings : null,
  regression_notes: regress ? regress.notes : null,
  counts,
}
