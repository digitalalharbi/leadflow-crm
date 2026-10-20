# shellcheck shell=bash
# Sourced by the on-server deploy scripts. Never executed.
#
# The server's checkout is a mirror of GitHub, not a place anybody works. A modified or
# untracked source file there is somebody's edit that is not in a commit — and the next
# image would carry it, or `git reset --hard` would erase it without a word. Either way the
# release stops being "this commit". So the deploy refuses, and changes nothing.
#
# Ignored files are not source and are never listed: .env.*, backups/, and the markers
# the deploy itself writes. `.last-good-commit*` is excluded by pathspec as well, because
# a server last deployed before it was added to .gitignore has one lying around.
require_clean_worktree() {
  local dirty
  dirty="$(git status --porcelain --untracked-files=all -- . ':(exclude).last-good-commit' ':(exclude).last-good-commit.tmp')"

  if [ -n "$dirty" ]; then
    {
      printf '\n\033[1;31mABORT DEPLOY\033[0m — the working tree on this server is not clean.\n'
      printf 'Nothing was fetched, built, migrated or restarted. Modified or untracked files:\n\n'
      printf '%s\n' "$dirty" | head -40 | sed 's/^/    /'
      printf '\nEvery change belongs in a commit on GitHub. Put it there, or discard it on the\n'
      printf 'server (`git checkout -- <file>` / `rm <file>`), then deploy again.\n\n'
    } >&2
    return 1
  fi
}
