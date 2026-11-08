#!/usr/bin/env bash
# Shared helpers for the WorktreeCreate / WorktreeRemove hooks. Sourced, never run directly.

MYSQL_CONTAINER="${WORKTREE_MYSQL_CONTAINER:-arospe-mysql-1}"

# Reads the hook JSON payload on stdin and sets $payload_name, $payload_worktree_path, $payload_base_path and $payload_detach.
# Claude Code only sends `name` and `cwd` to WorktreeCreate and only `worktree_path` to WorktreeRemove, so whatever is
# missing is derived: the base path is the main checkout's root (also correct from inside another worktree) and the
# worktree path defaults to <base>/.claude/worktrees/<name>. Explicit payload values still win.
read_hook_payload() {
    local fields cwd common_dir
    mapfile -t fields < <(php -r '
        $p = json_decode(stream_get_contents(STDIN), true) ?: [];
        foreach (["name", "worktree_path", "base_path", "cwd"] as $key) {
            echo ($p[$key] ?? ""), "\n";
        }
        echo ! empty($p["detach"]) ? "1" : "0", "\n";
    ')
    payload_name="${fields[0]:-}"
    payload_worktree_path="${fields[1]:-}"
    payload_base_path="${fields[2]:-}"
    cwd="${fields[3]:-}"
    payload_detach="${fields[4]:-0}"

    if [[ -z "$payload_base_path" ]]; then
        common_dir=$(git -C "${payload_worktree_path:-${cwd:-.}}" rev-parse --path-format=absolute --git-common-dir 2>/dev/null || true)
        [[ -n "$common_dir" ]] && payload_base_path=$(dirname "$common_dir")
    fi
    if [[ -z "$payload_name" && -n "$payload_worktree_path" ]]; then
        payload_name=$(basename "$payload_worktree_path")
    fi
    if [[ -z "$payload_worktree_path" && -n "$payload_name" && -n "$payload_base_path" ]]; then
        payload_worktree_path="$payload_base_path/.claude/worktrees/$payload_name"
    fi
}

# Maps a worktree name to its isolated test database: testing_<lowercase alnum/underscore, max 48 chars>.
testing_database_name() {
    local slug
    slug=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '_' | cut -c1-48)
    printf 'testing_%s' "$slug"
}

# Runs a SQL statement in the shared MySQL container; the password comes from the given env file.
run_mysql() {
    local env_file="$1" statement="$2" user password
    user=$(grep -E '^DB_USERNAME=' "$env_file" | head -1 | cut -d= -f2-)
    password=$(grep -E '^DB_PASSWORD=' "$env_file" | head -1 | cut -d= -f2-)
    docker exec -e MYSQL_PWD="$password" "$MYSQL_CONTAINER" mysql -u"${user:-sail}" -e "$statement"
}
