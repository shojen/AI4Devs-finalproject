#!/usr/bin/env bash
# Shared helpers for the WorktreeCreate / WorktreeRemove hooks. Sourced, never run directly.

MYSQL_CONTAINER="${WORKTREE_MYSQL_CONTAINER:-arospe-mysql-1}"

# Reads the hook JSON payload on stdin and sets $payload_name, $payload_worktree_path, $payload_base_path, $payload_detach.
read_hook_payload() {
    local fields
    mapfile -t fields < <(php -r '
        $p = json_decode(stream_get_contents(STDIN), true) ?: [];
        foreach (["name", "worktree_path", "base_path"] as $key) {
            echo ($p[$key] ?? ""), "\n";
        }
        echo ! empty($p["detach"]) ? "1" : "0", "\n";
    ')
    payload_name="${fields[0]:-}"
    payload_worktree_path="${fields[1]:-}"
    payload_base_path="${fields[2]:-}"
    payload_detach="${fields[3]:-0}"
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
