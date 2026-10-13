#!/usr/bin/env bash
# Local quality checks for local_alborhansignup: PHP syntax (php -l) and coding style (phpcs, moodle-extra).
#
# Usage:
#   dev/check.sh              run php -l and phpcs
#   dev/check.sh --install    install moodle-cs with composer into dev/tools (git-ignored), then exit
#   dev/check.sh --no-context run phpcs directly on the plugin tree (see "Component context" below)
#   dev/check.sh -h|--help    show this help
#
# Environment:
#   PHP                  PHP binary (default: php). Use the version of the site, e.g. PHP=php8.4.
#   PHPCS                phpcs to use (default: dev/tools/vendor/bin/phpcs, installed by --install).
#   PHPCS_STANDARD       coding standard (default: moodle-extra, a superset of the "moodle" standard used by CI).
#   PHPCS_MOODLE_BRANCH  Moodle branch number given to moodle-cs, e.g. 501 or 502 (default: 502).
#   COMPOSER             composer binary for --install (default: composer).
#
# Component context:
#   moodle-cs works out the Moodle branch and the component of each file from a Moodle code root above it.
#   Outside a Moodle checkout the sniffs that depend on them silently do nothing: language string ordering
#   (needs the branch, always passed below) and the @package tag and PHPUnit test case name checks (need the
#   component; moodle-cs 3.7 cannot list the components of a Moodle 5.1+ checkout either, so CI skips them).
#   By default this script therefore copies the plugin into a temporary minimal "Moodle root" and passes a
#   component list to moodle-cs. --no-context skips that and checks the plugin tree in place.
#
# Exit status: 0 when every check passes, 1 when a check reports a problem, 2 on usage or setup errors.

set -euo pipefail

plugindir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
php=${PHP:-php}
standard=${PHPCS_STANDARD:-moodle-extra}
branch=${PHPCS_MOODLE_BRANCH:-502}
component=local_alborhansignup
context=1

usage() {
    sed -n '2,/^$/{s/^# \{0,1\}//;p}' "${BASH_SOURCE[0]}"
}

install_tools() {
    local composer=${COMPOSER:-composer} tools="$plugindir/dev/tools"
    command -v "$composer" >/dev/null 2>&1 || { echo "check.sh: composer not found (set COMPOSER)" >&2; exit 2; }
    mkdir -p "$tools"
    [ -f "$tools/composer.json" ] || echo '{}' > "$tools/composer.json"
    # moodle-cs registers its standards with phpcs through this composer plugin.
    "$composer" config --working-dir="$tools" --no-interaction \
        allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
    "$composer" require --working-dir="$tools" --no-interaction --no-progress 'moodlehq/moodle-cs:^3.7'
    "$php" "$tools/vendor/bin/phpcs" --version
    "$php" "$tools/vendor/bin/phpcs" -i
}

while [ $# -gt 0 ]; do
    case "$1" in
        --install) install_tools; exit 0 ;;
        --no-context) context=0 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "check.sh: unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

command -v "$php" >/dev/null 2>&1 || { echo "check.sh: PHP binary not found: $php (set PHP)" >&2; exit 2; }

phpcs=${PHPCS:-$plugindir/dev/tools/vendor/bin/phpcs}
if [ ! -e "$phpcs" ]; then
    echo "check.sh: phpcs not found at $phpcs" >&2
    echo "check.sh: run 'dev/check.sh --install' or set PHPCS to an existing moodle-cs phpcs" >&2
    exit 2
fi

# The plugin's own PHP files: no vendored libraries, built JavaScript or local tooling.
pluginfiles() {
    (cd "$plugindir" && find . \
        \( -path ./.git -o -path ./thirdparty -o -path ./amd/build -o -path ./dev/tools \
           -o -name node_modules -o -name vendor \) -prune -o -type f -name '*.php' -print | sort)
}

status=0

# 1. PHP syntax.
echo "== php -l ($("$php" -r 'echo PHP_VERSION;'))"
count=0
failed=0
while IFS= read -r file; do
    count=$((count + 1))
    if ! out=$("$php" -d display_errors=1 -d log_errors=0 -l "$plugindir/${file#./}" 2>&1); then
        failed=$((failed + 1))
        echo "$out"
    fi
done < <(pluginfiles)
if [ "$failed" -gt 0 ]; then
    echo "php -l: $failed of $count files have syntax errors"
    status=1
else
    echo "php -l: $count files, no syntax errors"
fi

# 2. Coding style.
# Run phpcs with $PHP when it is a PHP script (composer proxy or phar), otherwise execute it as is.
phpcscmd=("$phpcs")
if [[ "$phpcs" == *.phar ]] || head -n 1 "$phpcs" | grep -q php; then
    phpcscmd=("$php" "$phpcs")
fi

# Same settings as "moodle-plugin-ci phpcs": no TODO or licence regex checks.
phpcsargs=(
    "--standard=$standard" --extensions=php -s --no-cache --report-width=132
    --runtime-set moodleTodoCommentRegex ''
    --runtime-set moodleLicenseRegex ''
    --runtime-set moodleBranch "$branch"
    '--ignore=*/thirdparty/*,*/amd/build/*,*/dev/tools/*,*/node_modules/*,*/vendor/*'
)
[ -t 1 ] && phpcsargs+=(--colors)

echo "== phpcs ($standard, moodleBranch $branch, $([ "$context" -eq 1 ] && echo "with" || echo "without") component context)"
if [ "$context" -eq 1 ]; then
    command -v rsync >/dev/null 2>&1 || { echo "check.sh: rsync is needed for the component context (or use --no-context)" >&2; exit 2; }
    tmp=$(mktemp -d "${TMPDIR:-/tmp}/alborhansignup-phpcs.XXXXXX")
    trap 'rm -rf "$tmp"' EXIT
    # moodle-cs accepts an explicit moodleRoot when it holds version.php and config-dist.php; the component
    # list maps the plugin directory inside that root to its component name.
    mkdir -p "$tmp/root/local"
    : > "$tmp/root/version.php"
    : > "$tmp/root/config-dist.php"
    rsync -a --exclude=/.git --exclude=/thirdparty --exclude=/amd/build --exclude=/dev/tools \
        --exclude=node_modules --exclude=vendor "$plugindir/" "$tmp/root/local/alborhansignup/"
    echo "plugin,$component,$tmp/root/local/alborhansignup" > "$tmp/components.txt"
    target="$tmp/root/local/alborhansignup"
    phpcsargs+=(
        --runtime-set moodleRoot "$tmp/root"
        --runtime-set moodleComponentsListPath "$tmp/components.txt"
    )
else
    target="$plugindir"
fi

if (cd "$target" && "${phpcscmd[@]}" "${phpcsargs[@]}" "--basepath=$target" .); then
    echo "phpcs: no errors or warnings"
else
    echo "phpcs: problems found (warnings fail too, as with --max-warnings 0 in CI);" \
        "'phpcbf' can fix the ones marked [x]"
    status=1
fi

exit "$status"
