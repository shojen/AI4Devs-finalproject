#!/usr/bin/env bash
# WorktreeRemove hook: drops the worktree's isolated test database, then removes the git worktree.
# The branch is deliberately kept (it may hold unmerged work); delete it by hand once merged.
# Contract: https://code.claude.com/docs/en/hooks#worktreeremove

set -euo pipefail
exec 3>&1 1>&2

source "$(dirname "${BASH_SOURCE[0]}")/worktree-lib.sh"

read_hook_payload <<<"$(cat)"
if [[ -z "$payload_worktree_path" || -z "$payload_base_path" ]]; then
    echo "worktree-remove: worktree_path and base_path are required in the hook payload" >&2
    exit 1
fi

database=$(testing_database_name "$(basename "$payload_worktree_path")")
env_file="$payload_base_path/arospe/.env.testing"

# Safety net: only ever drop a per-worktree database, never `arospe` or the shared `testing`.
if [[ "$database" == testing_* && -f "$env_file" ]]; then
    run_mysql "$env_file" "DROP DATABASE IF EXISTS \`$database\`;" \
        || echo "worktree-remove: WARNING could not drop database $database" >&2
fi

if [[ -d "$payload_worktree_path" ]]; then
    git -C "$payload_base_path" worktree remove --force "$payload_worktree_path"
fi
