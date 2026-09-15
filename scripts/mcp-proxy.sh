#!/bin/sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
credentials_file="$project_root/.wp-env.mcp.local"

if [ -f "$credentials_file" ]; then
	set -a
	. "$credentials_file"
	set +a
fi

WP_API_URL=${WP_API_URL:-http://localhost:8888/wp-json/mcp/mcp-adapter-default-server}
WP_API_USERNAME=${WP_API_USERNAME:-wp-ai-agent}
OAUTH_ENABLED=${OAUTH_ENABLED:-false}
export WP_API_URL WP_API_USERNAME OAUTH_ENABLED

if [ -z "${WP_API_PASSWORD:-}" ] && command -v security >/dev/null 2>&1; then
	WP_API_PASSWORD=$(security find-generic-password -a "$WP_API_USERNAME" -s wp-ai-fragments-mcp -w 2>/dev/null || true)
	export WP_API_PASSWORD
fi

: "${WP_API_URL:?WP_API_URL is required}"
: "${WP_API_USERNAME:?WP_API_USERNAME is required}"
: "${WP_API_PASSWORD:?WP_API_PASSWORD is required}"

exec npx -y @automattic/mcp-wordpress-remote@latest
