#!/usr/bin/env bash
#
# purge-secrets.sh — remove the committed local configuration files from
# every commit of a repository.
#
# WHAT IT DOES
#   1. makes a *mirror* clone of the repository in a temporary directory;
#   2. rewrites that clone so that
#        application/config/database.local.php
#        application/config/config.local.php
#      do not exist in any commit, on any ref;
#   3. verifies the rewrite;
#   4. prints the force-push command for you to run yourself.
#
# WHAT IT DOES NOT DO
#   It never touches your working copy and never pushes. Nothing is published
#   until you run the command it prints, which is deliberate: a rewrite
#   changes every commit id, closes open pull requests' history, and must come
#   AFTER the leaked credentials have been rotated. See SECURITY.md.
#
# USAGE
#   scripts/purge-secrets.sh https://github.com/<owner>/<repo>.git
#
#   --push-dry-run   also print (not run) the ref update for review
#
set -euo pipefail

RED=$'\033[31m'; YEL=$'\033[33m'; GRN=$'\033[32m'; RST=$'\033[0m'
die() { printf '%s%s%s\n' "$RED" "$*" "$RST" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

REPO_URL="${1:-}"
case "$REPO_URL" in
  ''|-h|--help)
    say "usage: $0 <repository-url>"
    say ""
    say "Example: $0 https://github.com/owner/repo.git"
    say "Rotate the database password and encryption key first — see SECURITY.md."
    exit 2
    ;;
esac

command -v git >/dev/null 2>&1 || die "git is required."

WORK="$(mktemp -d "${TMPDIR:-/tmp}/wms-purge.XXXXXX")"
cleanup() { say ""; say "${YEL}Temporary mirror kept at:${RST} $WORK/repo.git"; }
trap cleanup EXIT

say "==> mirror-cloning $REPO_URL"
git clone --quiet --mirror --no-local "$REPO_URL" "$WORK/repo.git"

cd "$WORK/repo.git"

if [ -f shallow ]; then
  die "the clone is shallow, so it does not contain the full history. Use the
canonical remote URL (not a local path) or run 'git fetch --unshallow' in the
source repository first."
fi

BEFORE="$(git rev-list --all --count)"
say "==> $BEFORE commits across $(git for-each-ref --format='%(refname)' refs/heads refs/tags | wc -l | tr -d ' ') branches/tags"

if git log --all --oneline -- \
     application/config/database.local.php \
     application/config/config.local.php | grep -q .; then
  say "==> the files are present; rewriting"
else
  say "${GRN}==> already absent from every ref — nothing to do.${RST}"
  exit 0
fi

if command -v git-filter-repo >/dev/null 2>&1; then
  say "==> rewriting with git-filter-repo"
  git filter-repo --force --invert-paths \
    --path application/config/database.local.php \
    --path application/config/config.local.php >/dev/null
elif python3 -c 'import git_filter_repo' >/dev/null 2>&1; then
  say "==> rewriting with python -m git_filter_repo"
  python3 -m git_filter_repo --force --invert-paths \
    --path application/config/database.local.php \
    --path application/config/config.local.php >/dev/null
else
  say "${YEL}==> git-filter-repo not found; falling back to git filter-branch${RST}"
  say "    (install it with 'pip install git-filter-repo' for a faster, safer run)"
  FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch --force --prune-empty \
    --index-filter 'git rm --cached --ignore-unmatch \
      application/config/database.local.php \
      application/config/config.local.php' \
    --tag-name-filter cat -- --all >/dev/null
  rm -rf refs/original
fi

# ── verify ────────────────────────────────────────────────────────────────
fail=0
if git log --all --oneline -- \
     application/config/database.local.php \
     application/config/config.local.php | grep -q .; then
  say "${RED}FAILED:${RST} the paths are still reachable from some ref."
  fail=1
fi
# no commit may carry the blob in its tree
if git rev-list --all --objects \
   | grep -E 'application/config/(database|config)\.local\.php$' >/dev/null; then
  say "${RED}FAILED:${RST} an object list still references the files."
  fail=1
fi
[ "$fail" -eq 0 ] || die "verification failed — do not push this clone."

AFTER="$(git rev-list --all --count)"
say "${GRN}==> verified: neither file exists in any commit.${RST}"
say "    commits: $BEFORE -> $AFTER (every commit id has changed)"

cat <<EOF

${YEL}NEXT — only after the credentials are rotated${RST}

  1. Review the rewritten history:
       cd $WORK/repo.git
       git log --oneline --all --decorate | head -20
       git ls-tree -r HEAD --name-only | grep '\.local\.php' || echo 'clean'

  2. Force-push the rewrite (this is destructive for collaborators):
       git push --mirror $(printf '%q' "$REPO_URL")

     Everyone with a clone must delete it and re-clone.

  3. GitHub keeps unreachable objects addressable by SHA and still has the
     old pull-request refs. Open a request at
     https://support.github.com/contact (or Settings -> Support) asking for a
     "repository cache purge / removal of unreachable objects" after the
     force-push, and treat any fork, CI cache or third-party mirror as still
     holding the old values.

  4. Re-enable anything the rewrite removed (branch protection, PRs).
     Open pull requests can be reopened from the rewritten branch.

${YEL}Reminder:${RST} the rewrite hides the old values from future clones.
It does NOT make them secret again — rotation is the fix.
EOF
