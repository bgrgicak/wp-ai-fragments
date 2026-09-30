#!/bin/sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
credentials_file="$project_root/.wp-env.mcp.local"
if [ -f "$credentials_file" ]; then
	set -a
	. "$credentials_file"
	set +a
fi
exec node "$project_root/experiments/native-admin/mcp-server.mjs"
