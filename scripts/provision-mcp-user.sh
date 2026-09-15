#!/bin/sh
set -eu

site_url=${WP_ENV_SITE_URL:-http://localhost:8888}
agent_username=${WP_MCP_USERNAME:-wp-ai-agent}
keychain_service=wp-ai-fragments-mcp
cookie_jar=$(mktemp)
login_page=$(mktemp)
admin_page=$(mktemp)

cleanup() {
	rm -f "$cookie_jar" "$login_page" "$admin_page"
}
trap cleanup EXIT

login_attempt=1
while [ "$login_attempt" -le 5 ]; do
	curl -fsS -c "$cookie_jar" "$site_url/wp-login.php" -o "$login_page"
	curl -fsS -L -b "$cookie_jar" -c "$cookie_jar" \
		--data-urlencode 'log=admin' \
		--data-urlencode 'pwd=password' \
		--data-urlencode 'wp-submit=Log In' \
		--data-urlencode "redirect_to=$site_url/wp-admin/" \
		--data-urlencode 'testcookie=1' \
		"$site_url/wp-login.php" -o "$admin_page"
	if grep -q 'wp-admin-bar-logout' "$admin_page"; then
		break
	fi
	login_attempt=$(( login_attempt + 1 ))
	sleep 2
done

if [ "$login_attempt" -gt 5 ]; then
	echo 'Could not log in to the local WordPress site with the wp-env development credentials.' >&2
	exit 1
fi

api_nonce=$(perl -ne 'if (/wpApiSettings\s*=\s*(\{.*\});/) { print $1; exit }' "$admin_page" | jq -r '.nonce // empty')
if [ -z "$api_nonce" ]; then
	echo 'Could not obtain an authenticated WordPress REST nonce.' >&2
	exit 1
fi

users_body=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" "$site_url/wp-json/wp/v2/users?search=$agent_username&context=edit")
agent_id=$(printf '%s' "$users_body" | perl -ne 'if (/\"id\":(\d+).*?\"username\":\"wp-ai-agent\"/s) { print $1; exit }')

if [ -z "$agent_id" ]; then
	generated_password=$(openssl rand -hex 24)
	user_payload=$(jq -cn \
		--arg username "$agent_username" \
		--arg email "$agent_username@example.test" \
		--arg password "$generated_password" \
		'{username:$username,email:$email,password:$password,roles:["administrator"]}')
	user_body=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" -H 'Content-Type: application/json' \
		-X POST --data "$user_payload" "$site_url/wp-json/wp/v2/users")
	agent_id=$(printf '%s' "$user_body" | perl -ne 'if (/\"id\":(\d+)/) { print $1; exit }')
fi

if [ -z "$agent_id" ]; then
	echo 'Could not create or locate the dedicated WordPress MCP user.' >&2
	exit 1
fi

app_id=$(uuidgen | tr '[:upper:]' '[:lower:]')
app_payload=$(jq -cn --arg name 'Codex local development' --arg app_id "$app_id" '{name:$name,app_id:$app_id}')
app_body=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" -H 'Content-Type: application/json' \
	-X POST --data "$app_payload" "$site_url/wp-json/wp/v2/users/$agent_id/application-passwords")
app_password=$(printf '%s' "$app_body" | perl -ne 'if (/\"password\":\"([^\"]+)\"/) { print $1; exit }')

if [ -z "$app_password" ]; then
	echo 'Could not create a WordPress application password.' >&2
	exit 1
fi

if ! command -v security >/dev/null 2>&1; then
	echo 'The application password was created, but macOS Keychain is unavailable. Configure .wp-env.mcp.local manually.' >&2
	exit 1
fi

security add-generic-password -a "$agent_username" -s "$keychain_service" -w "$app_password" -U >/dev/null
echo "Dedicated MCP user is ready (WordPress user id $agent_id); its application password is stored in macOS Keychain."
