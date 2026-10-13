#!/usr/bin/env bash
# Show the GitHub Actions result for a commit of this repository, including the PHPUnit/Behat failure output
# that the workflow publishes as annotations (job logs need authentication; annotations of a public
# repository do not).
#
# Usage:
#   dev/ci-status.sh [options] [commit]     commit: anything git rev-parse accepts (default: HEAD) or a full SHA
#
# Options:
#   --wait            poll until the runs for the commit have finished (or appear, right after a push)
#   --interval SECS   seconds between polls with --wait (default: 60)
#   --timeout SECS    give up waiting after this many seconds (default: 2700)
#   --steps           list the steps of every job (default: only of the jobs that did not succeed)
#   -h, --help        show this help
#
# Environment:
#   GITHUB_TOKEN       optional token, only to raise the API rate limit (60 unauthenticated requests per hour)
#   GITHUB_REPOSITORY  owner/repo to query instead of the one in "git remote get-url origin"
#
# Needs curl and python3. Read-only: it only sends GET requests to the GitHub REST API.
#
# Exit status: 0 all runs succeeded, 1 a run failed or was cancelled, 2 no run yet or still running,
# 3 usage or API error.

set -euo pipefail

plugindir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
wait=0
interval=60
timeout=2700
steps=0
commit=HEAD

usage() {
    sed -n '2,/^$/{s/^# \{0,1\}//;p}' "${BASH_SOURCE[0]}"
}

die() {
    echo "ci-status.sh: $*" >&2
    exit 3
}

while [ $# -gt 0 ]; do
    case "$1" in
        --wait) wait=1 ;;
        --interval) [ $# -ge 2 ] || die "--interval needs a value"; interval=$2; shift ;;
        --timeout) [ $# -ge 2 ] || die "--timeout needs a value"; timeout=$2; shift ;;
        --steps) steps=1 ;;
        -h|--help) usage; exit 0 ;;
        -*) usage >&2; die "unknown option: $1" ;;
        *) commit=$1 ;;
    esac
    shift
done
[[ "$interval" =~ ^[0-9]+$ ]] && [ "$interval" -gt 0 ] || die "--interval needs a positive number of seconds"
[[ "$timeout" =~ ^[0-9]+$ ]] || die "--timeout needs a number of seconds"
command -v curl >/dev/null 2>&1 || die "curl not found"
command -v python3 >/dev/null 2>&1 || die "python3 not found"

# Repository slug (owner/repo) from the origin remote: git@github.com:o/r.git, https://github.com/o/r(.git),
# ssh://git@github.com/o/r.git.
if [ -n "${GITHUB_REPOSITORY:-}" ]; then
    slug=$GITHUB_REPOSITORY
else
    url=$(git -C "$plugindir" remote get-url origin 2>/dev/null) || die "no 'origin' remote (or set GITHUB_REPOSITORY)"
    url=${url%/}
    url=${url%.git}
    [[ "$url" =~ github\.com[:/]+([^/]+)/([^/]+)$ ]] || die "origin is not a GitHub repository: $url"
    slug="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
fi

if sha=$(git -C "$plugindir" rev-parse --verify --quiet "${commit}^{commit}" 2>/dev/null); then
    subject=$(git -C "$plugindir" log -1 --format='%h %s' "$sha")
elif [[ "$commit" =~ ^[0-9a-f]{40}$ ]]; then
    sha=$commit
    subject=$commit
else
    die "unknown commit: $commit"
fi

tmp=$(mktemp -d "${TMPDIR:-/tmp}/alborhansignup-ci.XXXXXX")
trap 'rm -rf "$tmp"' EXIT

# api PATH OUTFILE: GET https://api.github.com/PATH into OUTFILE.
api() {
    local code msg
    local args=(-sS -o "$2" -w '%{http_code}'
        -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28')
    if [ -n "${GITHUB_TOKEN:-}" ]; then
        # Passed through a config file descriptor so the token does not show up in the process list.
        code=$(curl "${args[@]}" --config <(printf 'header = "Authorization: Bearer %s"\n' "$GITHUB_TOKEN") \
            "https://api.github.com/$1") || die "request failed: $1"
    else
        code=$(curl "${args[@]}" "https://api.github.com/$1") || die "request failed: $1"
    fi
    if [ "$code" != 200 ]; then
        msg=$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1])).get("message", ""))' "$2" 2>/dev/null || true)
        if [ "$code" = 403 ] || [ "$code" = 429 ]; then
            msg="$msg (rate limit? unauthenticated clients get 60 requests per hour; set GITHUB_TOKEN or wait)"
        fi
        die "GitHub API returned HTTP $code for $1: $msg"
    fi
}

# Helpers that read the API responses. JSON only; no third-party modules.
read -r -d '' pyprog <<'PY' || true
import json
import re
import sys


def load(path):
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def duration(item):
    from datetime import datetime
    start, end = item.get("started_at"), item.get("completed_at")
    if not start or not end:
        return ""
    fmt = "%Y-%m-%dT%H:%M:%SZ"
    seconds = int((datetime.strptime(end, fmt) - datetime.strptime(start, fmt)).total_seconds())
    return "%dm%02ds" % divmod(max(seconds, 0), 60)


def state(item):
    return item.get("conclusion") or item.get("status") or "unknown"


def runs(path):
    # Latest run per workflow (the API lists the newest first), as tab-separated fields.
    seen = set()
    for run in load(path).get("workflow_runs", []):
        if run["workflow_id"] in seen:
            continue
        seen.add(run["workflow_id"])
        print("\t".join(str(field) for field in (
            run["id"], run["status"], run.get("conclusion") or "", run["name"], run["event"],
            run.get("run_attempt", 1), run["html_url"])))


def jobs(path, allsteps):
    for job in load(path).get("jobs", []):
        print("  [%s] %s  %s" % (state(job), job["name"], duration(job)))
        if allsteps or job.get("conclusion") != "success":
            for step in job.get("steps", []):
                print("      %-11s %s" % (state(step), step["name"]))


def failed_jobs(path):
    for job in load(path).get("jobs", []):
        if job.get("conclusion") in ("failure", "timed_out", "cancelled", "startup_failure"):
            print("%s\t%s" % (job["check_run_url"].split("api.github.com/", 1)[1], job["name"]))


def output_group(title):
    # "PHPUnit output (8.4 MOODLE_502_STABLE mariadb) phpunit.log.part.00" -> "PHPUnit output (8.4 MOODLE_502_STABLE
    # mariadb)". Older runs joined the matrix with commas, which cut the title at the first comma.
    return re.sub(r"\s+\S+\.part\.\d+$", "", title)


def annotations(path):
    items = load(path)
    output = [a for a in items if "output" in (a.get("title") or "").lower()]
    # The API does not return annotations in the order they were written, but the part suffix of the title and
    # start_line (their line in the log) restore it. PHPUnit output first, then Behat.
    output.sort(key=lambda a: ("phpunit" not in a["title"].lower(), output_group(a["title"]), a["title"],
                               a.get("start_line") or 0))
    for item in output:
        message = item.get("message") or ""
        # The runner normally decodes the workflow command escapes; decode them if it did not.
        if "\n" not in message and "%0A" in message:
            message = message.replace("%0D", "").replace("%0A", "\n").replace("%25", "%")
        name = output_group(item["title"])
        group = [a for a in output if output_group(a["title"]) == name]
        print("----- %s [part %d of %d]" % (name, group.index(item) + 1, len(group)))
        print(message.rstrip("\n"))
    for item in items:
        if item in output:
            continue
        first = (item.get("message") or "").strip().splitlines()[:1]
        print("  %s %s:%s %s %s" % (item.get("annotation_level"), item.get("path"), item.get("start_line"),
                                     item.get("title") or "", first[0] if first else ""))
    if not output:
        print("  (no PHPUnit/Behat output annotations: the failure is in another step, or the workflow of this"
              " commit predates them; see the job page)")


command, args = sys.argv[1], sys.argv[2:]
if command == "runs":
    runs(args[0])
elif command == "jobs":
    jobs(args[0], args[1] == "1")
elif command == "failed-jobs":
    failed_jobs(args[0])
elif command == "annotations":
    annotations(args[0])
PY

py() {
    python3 -c "$pyprog" "$@"
}

echo "Commit $subject"
echo "Repository https://github.com/$slug"

start=$(date +%s)
while :; do
    api "repos/$slug/actions/runs?head_sha=$sha&per_page=50" "$tmp/runs.json"
    py runs "$tmp/runs.json" > "$tmp/runs.tsv"
    total=$(wc -l < "$tmp/runs.tsv")
    pending=$(awk -F '\t' '$2 != "completed"' "$tmp/runs.tsv" | wc -l)
    if [ "$total" -gt 0 ] && [ "$pending" -eq 0 ]; then
        break
    fi
    if [ "$wait" -eq 0 ]; then
        break
    fi
    if [ $(( $(date +%s) - start )) -ge "$timeout" ]; then
        echo "ci-status.sh: still not finished after ${timeout}s" >&2
        break
    fi
    if [ "$total" -eq 0 ]; then
        echo "$(date +%H:%M:%S) no run yet for ${sha:0:7}; checking again in ${interval}s" >&2
    else
        echo "$(date +%H:%M:%S) $pending of $total run(s) not finished; checking again in ${interval}s" >&2
    fi
    sleep "$interval"
done

if [ "$total" -eq 0 ]; then
    echo "No GitHub Actions run for ${sha:0:7} (not pushed yet, or the workflow did not trigger)."
    exit 2
fi

failed=0
running=0
while IFS=$'\t' read -r id status conclusion name event attempt htmlurl; do
    echo
    echo "Run $id  $name  ($event, attempt $attempt)  $status${conclusion:+: $conclusion}"
    echo "$htmlurl"
    api "repos/$slug/actions/runs/$id/jobs?per_page=100" "$tmp/jobs-$id.json"
    py jobs "$tmp/jobs-$id.json" "$steps"
    if [ "$status" != completed ]; then
        running=1
        continue
    fi
    case "$conclusion" in
        success|skipped|neutral) ;;
        *) failed=1 ;;
    esac
    while IFS=$'\t' read -r checkrun jobname; do
        echo
        echo "== Annotations of failed job: $jobname"
        api "$checkrun/annotations?per_page=100" "$tmp/annotations.json"
        py annotations "$tmp/annotations.json"
    done < <(py failed-jobs "$tmp/jobs-$id.json")
done < "$tmp/runs.tsv"

if [ "$failed" -eq 1 ]; then
    exit 1
elif [ "$running" -eq 1 ]; then
    exit 2
fi
exit 0
