#!/usr/bin/env bash
# Build amd/build and run the grunt linters for local_alborhansignup inside a separate Moodle checkout.
#
# Usage:
#   export MOODLE_DEV=<dev-checkout>
#   ./dev/build.sh
#
# MOODLE_DEV must be a Moodle 5.1+ code checkout (with public/) used only for development, never a production
# site, with its JavaScript dependencies installed:
#   cd <dev-checkout> && nvm install && nvm use && npm ci
#
# A build checkout needs no config.php. To protect installed sites (including production trees), the script
# refuses to run when <dev-checkout>/config.php exists, unless MOODLE_DEV_ALLOW_CONFIG=1 is set (only for a
# development site that you are sure is not production).
#
# What it does:
#   1. copies the plugin into <dev-checkout>/public/local/alborhansignup as a real directory (grunt resolves
#      real paths to detect the component, so a symlink to this repository would not work);
#   2. runs "npx grunt amd --max-lint-warnings=0" in its amd/ directory (eslint + rollup; any warning fails);
#   3. runs "npx grunt gherkinlint stylelint:css" in the plugin directory (Behat features, styles.css);
#   4. copies amd/build back into this repository. Commit amd/src and amd/build together.
#
# If <dev-checkout>/public/local/alborhansignup already is this repository (a clone inside the checkout), the
# build runs in place and nothing is copied.
#
# Exit status: 0 on success, 1 when grunt fails, 2 on usage or setup errors.

set -eo pipefail

plugindir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

die() {
    echo "build.sh: $*" >&2
    exit 2
}

case "${1:-}" in
    -h|--help) sed -n '2,/^$/{s/^# \{0,1\}//;p}' "${BASH_SOURCE[0]}"; exit 0 ;;
    '') ;;
    *) die "unknown argument: $1 (see --help)" ;;
esac

[ -n "${MOODLE_DEV:-}" ] || die "set MOODLE_DEV to a separate Moodle checkout used for builds, e.g. 'export MOODLE_DEV=<dev-checkout>', then run $0 again"
[ -d "$MOODLE_DEV" ] || die "MOODLE_DEV is not a directory: $MOODLE_DEV"
moodledev=$(cd "$MOODLE_DEV" && pwd -P)
[ -f "$moodledev/public/version.php" ] && [ -f "$moodledev/Gruntfile.js" ] ||
    die "$moodledev is not a Moodle 5.1+ code checkout (public/version.php and Gruntfile.js expected)"
if [ -e "$moodledev/config.php" ] && [ "${MOODLE_DEV_ALLOW_CONFIG:-}" != 1 ]; then
    die "$moodledev/config.php exists: this looks like an installed site, possibly production. A build checkout needs no config.php. Set MOODLE_DEV_ALLOW_CONFIG=1 only for a development site."
fi
[ -d "$moodledev/node_modules" ] ||
    die "$moodledev has no node_modules: run 'cd $moodledev && nvm install && nvm use && npm ci' first"
command -v rsync >/dev/null 2>&1 || die "rsync not found"

# Node: use the version the checkout asks for (.nvmrc; lts/jod = Node 22 for Moodle 5.2) when nvm is installed.
nvmsh="${NVM_DIR:-$HOME/.nvm}/nvm.sh"
if [ -s "$nvmsh" ]; then
    # nvm is not compatible with "set -e".
    set +e
    # shellcheck disable=SC1090
    . "$nvmsh" --no-use
    rc=0
    if [ -f "$moodledev/.nvmrc" ]; then
        pushd "$moodledev" >/dev/null && nvm use
        rc=$?
        popd >/dev/null || rc=2
    fi
    set -e
    [ "$rc" -eq 0 ] || die "nvm cannot use the Node version of $moodledev/.nvmrc: run 'cd $moodledev && nvm install'"
fi
command -v node >/dev/null 2>&1 || die "node not found (install nvm, then 'nvm install' in $moodledev)"
command -v npx >/dev/null 2>&1 || die "npx not found"

# Check node against package.json "engines" (e.g. ">=22.11.0 <23"): only the major versions are compared.
node -e '
    const range = (require(process.argv[1]).engines || {}).node || "";
    const major = Number(process.versions.node.split(".")[0]);
    const min = range.match(/>=\s*(\d+)/);
    const max = range.match(/<\s*(\d+)/);
    if ((min && major < Number(min[1])) || (max && major >= Number(max[1]))) {
        console.error(`build.sh: node ${process.version} does not match engines.node "${range}" of the checkout`);
        process.exit(1);
    }
' "$moodledev/package.json" || die "use the Node version of the checkout ('nvm use' in $moodledev)"

target="$moodledev/public/local/alborhansignup"
if [ -L "$target" ]; then
    die "$target is a symlink; grunt needs a real directory (remove the link and run again)"
fi

inplace=0
if [ -d "$target" ] && [ "$(cd "$target" && pwd -P)" = "$(cd "$plugindir" && pwd -P)" ]; then
    inplace=1
elif [ -e "$target/.git" ]; then
    die "$target is another git clone; run dev/build.sh from that clone, or remove it so this script can manage the copy"
fi

echo "== node $(node -v), Moodle checkout $moodledev"
if [ "$inplace" -eq 0 ]; then
    echo "== copying the plugin to $target"
    mkdir -p "$target"
    # --delete keeps the copy identical; excluded paths are neither copied nor deleted on the receiving side.
    rsync -a --delete --exclude=/.git --exclude=/dev/tools --exclude=node_modules \
        "$plugindir/" "$target/"
fi

status=0
echo "== grunt amd (eslint, rollup)"
(cd "$target/amd" && npx grunt amd --max-lint-warnings=0) || status=1

echo "== grunt gherkinlint stylelint:css"
(cd "$target" && npx grunt gherkinlint stylelint:css) || status=1

if [ "$status" -ne 0 ]; then
    echo "build.sh: grunt failed; amd/build was not copied back" >&2
    exit 1
fi

if [ "$inplace" -eq 0 ]; then
    echo "== copying amd/build back"
    rsync -a --delete "$target/amd/build/" "$plugindir/amd/build/"
fi

if git -C "$plugindir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    changes=$(git -C "$plugindir" status --porcelain -- amd/build)
    if [ -n "$changes" ]; then
        echo "amd/build changed; commit it together with amd/src:"
        echo "$changes"
    else
        echo "amd/build is up to date"
    fi
fi
