#!/usr/bin/env bash
# Pulls the target branch from git and lints every changed PHP file before
# leaving it live -- replaces the admin-shell hand-patch-and-diff workflow
# that let a dead duplicate file and a missing require silently ship in a
# past session, because nothing automated ever checked "does this match
# git" or "does this even parse" before the old file was overwritten.
#
# Run this ON THE SERVER (via an admin shell or SSH session), from anywhere
# inside the site's git checkout. Aborts loudly instead of touching
# anything if the working tree isn't clean -- a real deploy must never
# silently discard local drift it doesn't understand.
#
# JS files are NOT syntax-checked here -- this server has no node in PATH,
# so `node --check` has to happen before code reaches this branch (CI, or
# whatever pushed the commit). This script only guarantees changed PHP
# parses; it will say how many JS files changed so that's not silently
# assumed fine.
#
# Usage: ./scripts/deploy.sh [branch]   (defaults to "main")

set -euo pipefail

BRANCH="${1:-main}"
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SITE_ROOT"

if [ ! -d .git ]; then
    echo "ERROR: $SITE_ROOT is not a git checkout -- refusing to deploy." >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: working tree has uncommitted or untracked changes -- refusing" >&2
    echo "to deploy over unknown local drift. Review 'git status', commit or" >&2
    echo "discard what's there, then re-run." >&2
    exit 1
fi

OLD_HEAD="$(git rev-parse HEAD)"

echo "Fetching origin/$BRANCH..."
git fetch origin "$BRANCH"

NEW_HEAD="$(git rev-parse "origin/$BRANCH")"
if [ "$OLD_HEAD" = "$NEW_HEAD" ]; then
    echo "Already up to date at $OLD_HEAD -- nothing to deploy."
    exit 0
fi

echo "Checking out $BRANCH ($OLD_HEAD -> $NEW_HEAD)..."
git checkout -B "$BRANCH" "origin/$BRANCH"

echo "Linting every PHP file that changed..."
LINT_LOG="$(mktemp)"
FAILED=0
while IFS= read -r -d '' file; do
    [ -f "$file" ] || continue # a changed file can also mean "deleted"
    if ! php -l "$file" > "$LINT_LOG" 2>&1; then
        echo "SYNTAX ERROR in $file:"
        cat "$LINT_LOG"
        FAILED=1
    fi
done < <(git diff --name-only -z "$OLD_HEAD" "$NEW_HEAD" -- '*.php')
rm -f "$LINT_LOG"

JS_CHANGED="$(git diff --name-only "$OLD_HEAD" "$NEW_HEAD" -- '*.js' | wc -l | tr -d ' ')"
if [ "$JS_CHANGED" -gt 0 ]; then
    echo "NOTE: $JS_CHANGED JS file(s) changed. This server has no node, so their"
    echo "syntax was NOT re-checked here -- confirm they were validated before push."
fi

if [ "$FAILED" -ne 0 ]; then
    echo "Lint failure(s) above -- rolling back to $OLD_HEAD." >&2
    git checkout -B "$BRANCH" "$OLD_HEAD"
    exit 1
fi

echo "Deployed $BRANCH at $NEW_HEAD -- every changed PHP file passes php -l."
