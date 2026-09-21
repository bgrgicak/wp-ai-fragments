#!/bin/sh
set -eu

site_url=${WP_ENV_SITE_URL:-http://localhost:8888}
agent_username=${WP_MCP_USERNAME:-wp-ai-agent}
keychain_service=wp-ai-fragments-mcp
endpoint="$site_url/wp-json/wp-ai-fragments/v1/mcp"

if [ -z "${WP_API_PASSWORD:-}" ] && command -v security >/dev/null 2>&1; then
	WP_API_PASSWORD=$(security find-generic-password -a "$agent_username" -s "$keychain_service" -w 2>/dev/null || true)
fi

: "${WP_API_PASSWORD:?Run npm run dev:provision or set WP_API_PASSWORD.}"

initialize_response=$(curl -fsS -D - -o /dev/null -u "$agent_username:$WP_API_PASSWORD" \
	-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
	--data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{"extensions":{"io.modelcontextprotocol/ui":{}}},"clientInfo":{"name":"wp-ai-fragments-smoke","version":"0.1.0"}}}' \
	"$endpoint")
session_id=$(printf '%s' "$initialize_response" | awk 'BEGIN{IGNORECASE=1} /^mcp-session-id:/ {gsub("\r", "", $2); print $2}')

if [ -z "$session_id" ]; then
	echo 'The WP AI Fragments MCP server did not establish a session.' >&2
	exit 1
fi

request() {
	curl -fsS -u "$agent_username:$WP_API_PASSWORD" \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		-H "Mcp-Session-Id: $session_id" --data "$1" "$endpoint"
}

tools_result=$(request '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
resource_result=$(request '{"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"ui://wp-ai-fragments/fragment-editor-v2.html"}}')
legacy_resource_result=$(request '{"jsonrpc":"2.0","id":6,"method":"resources/read","params":{"uri":"ui://wp-ai-fragments/fragment-viewer.html"}}')
render_result=$(request '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"ui-render-fragment","arguments":{"fragment_id":"post-field/product/content"}}}')

printf '%s' "$tools_result" | jq -e \
	'.result.tools[] | select(.name == "ui-render-fragment" and ._meta.ui.resourceUri == "ui://wp-ai-fragments/fragment-editor-v2.html")' >/dev/null
printf '%s' "$tools_result" | jq -e \
	'.result.tools[] | select(.name == "ui-update-post-field" and ._meta.ui.visibility == ["app"])' >/dev/null
printf '%s' "$resource_result" | jq -e \
	'.result.contents[0] | select(.mimeType == "text/html;profile=mcp-app" and ._meta.ui.prefersBorder == true and (._meta.ui.csp.frameDomains == null) and (._meta["openai/widgetCSP"].redirect_domains | length) == 1 and (.text | contains("WP AI Fragments Viewer")) and (.text | contains("ui-update-post-field")) and (.text | contains("openExternal")) and (.text | contains("Focused WordPress URL")) and (.text | contains("<iframe") | not))' >/dev/null
printf '%s' "$legacy_resource_result" | jq -e \
	'.result.contents[0] | select(.uri == "ui://wp-ai-fragments/fragment-viewer.html" and .mimeType == "text/html;profile=mcp-app" and (.text | contains("ui-update-post-field")) and (.text | contains("<iframe") | not))' >/dev/null
printf '%s' "$render_result" | jq -e \
	'.result | select(.isError == false and (.structuredContent | has("html") | not) and (.structuredContent.url | contains("wp-admin/post.php?post=12&action=edit&wp_ai_fragments_focus=post-field/product/content")) and .structuredContent.mode == "post-field" and .structuredContent.post_id == 12 and .structuredContent.field == "content" and (.structuredContent.value | type == "string") and .structuredContent.editable == true)' >/dev/null

current_value=$(printf '%s' "$render_result" | jq -r '.result.structuredContent.value')
update_request=$(jq -cn --arg value "$current_value" '{jsonrpc:"2.0",id:5,method:"tools/call",params:{name:"ui-update-post-field",arguments:{post_id:12,field:"content",value:$value}}}')
update_result=$(request "$update_request")
printf '%s' "$update_result" | jq -e --arg value "$current_value" \
	'.result | select(.isError == false and .structuredContent.post_id == 12 and .structuredContent.field == "content" and .structuredContent.value == $value)' >/dev/null

echo 'Rendering smoke test passed.'
