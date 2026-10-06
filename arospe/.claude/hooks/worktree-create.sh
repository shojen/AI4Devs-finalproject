#!/usr/bin/env bash
# WorktreeCreate hook: replaces Claude Code's default `git worktree add`.
#
# The Laravel app lives in the arospe/ subfolder of the repository, so the session must start in
# <worktree>/arospe (where .claude/, CLAUDE.md, docs/ and artisan are). This hook therefore:
#   1. creates the git worktree from the current HEAD,
#   2. copies .env / .env.testing from the main checkout (DB_HOST=127.0.0.1, per-worktree test database),
#   3. creates that database and installs dependencies / builds assets (best effort, warnings only),
#   4. prints <worktree>/arospe as the ONLY line on stdout, which becomes the session working directory.
# Everything else goes to stderr. Contract: https://code.claude.com/docs/en/hooks#worktreecreate

set -euo pipefail
exec 3>&1 1>&2

source "$(dirname "${BASH_SOURCE[0]}")/worktree-lib.sh"

read_hook_payload <<<"$(cat)"
if [[ -z "$payload_name" || -z "$payload_worktree_path" || -z "$payload_base_path" ]]; then
    echo "worktree-create: name, worktree_path and base_path are required in the hook payload" >&2
    exit 1
fi

main_app_dir="$payload_base_path/arospe"
app_dir="$payload_worktree_path/arospe"
branch="worktree-$payload_name"
database=$(testing_database_name "$payload_name")

if [[ ! -d "$payload_worktree_path" ]]; then
    if [[ "$payload_detach" == "1" ]]; then
        git -C "$payload_base_path" worktree add --detach "$payload_worktree_path" HEAD
    elif git -C "$payload_base_path" show-ref --verify --quiet "refs/heads/$branch"; then
        git -C "$payload_base_path" worktree add "$payload_worktree_path" "$branch"
    else
        git -C "$payload_base_path" worktree add -b "$branch" "$payload_worktree_path" HEAD
    fi
fi

if [[ ! -d "$app_dir" ]]; then
    echo "worktree-create: $app_dir does not exist after creating the worktree" >&2
    exit 1
fi

for env_file in .env .env.testing; do
    if [[ -f "$main_app_dir/$env_file" && ! -f "$app_dir/$env_file" ]]; then
        cp "$main_app_dir/$env_file" "$app_dir/$env_file"
        sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' "$app_dir/$env_file"
    fi
done
if [[ -f "$app_dir/.env.testing" ]]; then
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$database/" "$app_dir/.env.testing"
fi

if [[ -f "$app_dir/.env.testing" ]]; then
    run_mysql "$app_dir/.env.testing" "CREATE DATABASE IF NOT EXISTS \`$database\`;" \
        || echo "worktree-create: WARNING could not create database $database (is $MYSQL_CONTAINER running?)" >&2
fi

cd "$app_dir"
composer install --no-interaction --prefer-dist || echo "worktree-create: WARNING composer install failed" >&2
npm ci || echo "worktree-create: WARNING npm ci failed" >&2
npm run build || echo "worktree-create: WARNING npm run build failed" >&2
php artisan storage:link --no-interaction || true

cat >&2 <<MSG
worktree-create: ready at $app_dir
  test database : $database
  run tests with: DB_DATABASE=$database php -d memory_limit=-1 vendor/bin/pest --filter=... > /tmp/out.log 2>&1
MSG

printf '%s\n' "$app_dir" >&3
