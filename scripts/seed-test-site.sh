#!/bin/sh
set -eu

site_url=${WP_ENV_SITE_URL:-http://localhost:8888}
admin_username=${WP_ENV_ADMIN_USERNAME:-admin}
admin_password=${WP_ENV_ADMIN_PASSWORD:-password}
product_slug=fragment-test-product
cookie_jar=$(mktemp)
admin_page=$(mktemp)

cleanup() {
	rm -f "$cookie_jar" "$admin_page"
}
trap cleanup EXIT

curl -fsS -c "$cookie_jar" "$site_url/wp-login.php" -o /dev/null
curl -fsS -L -b "$cookie_jar" -c "$cookie_jar" \
	--data-urlencode "log=$admin_username" \
	--data-urlencode "pwd=$admin_password" \
	--data-urlencode 'wp-submit=Log In' \
	--data-urlencode "redirect_to=$site_url/wp-admin/" \
	--data-urlencode 'testcookie=1' \
	"$site_url/wp-login.php" -o "$admin_page"

if ! grep -q 'wp-admin-bar-logout' "$admin_page"; then
	echo 'Could not log in to the local WordPress site with the wp-env development credentials.' >&2
	exit 1
fi

api_nonce=$(perl -ne 'if (/wpApiSettings\s*=\s*(\{.*\});/) { print $1; exit }' "$admin_page" | jq -r '.nonce // empty')
if [ -z "$api_nonce" ]; then
	echo 'Could not obtain an authenticated WordPress REST nonce.' >&2
	exit 1
fi

products=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" \
	"$site_url/wp-json/wc/v3/products?slug=$product_slug")
product_id=$(printf '%s' "$products" | jq -r '.[0].id // empty')

if [ -z "$product_id" ]; then
	product_payload=$(jq -cn \
		'{name:"Fragment Test Product",slug:"fragment-test-product",type:"simple",status:"publish",regular_price:"19.99",description:"A seeded product for exercising real wp-admin fragment discovery.",short_description:"Fragment discovery fixture.",manage_stock:true,stock_quantity:12}')
	product=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" -H 'Content-Type: application/json' \
		-X POST --data "$product_payload" "$site_url/wp-json/wc/v3/products")
	product_id=$(printf '%s' "$product" | jq -r '.id // empty')
fi

if [ -z "$product_id" ]; then
	echo 'Could not create or locate the WooCommerce product fixture.' >&2
	exit 1
fi

contact_forms=$(curl -fsS -b "$cookie_jar" -H "X-WP-Nonce: $api_nonce" \
	"$site_url/wp-json/contact-form-7/v1/contact-forms")
contact_form_count=$(printf '%s' "$contact_forms" | jq 'length')
contact_form_id=$(printf '%s' "$contact_forms" | jq -r '.[0].id // empty')

probe_screen() {
	curl -fsS -L -b "$cookie_jar" "$site_url$1" -o /dev/null
}

probe_screen '/wp-admin/index.php?wp_ai_fragments_discover=1'
probe_screen '/wp-admin/options-writing.php?wp_ai_fragments_discover=1'
probe_screen "/wp-admin/post.php?post=$product_id&action=edit&wp_ai_fragments_discover=1"
probe_screen '/wp-admin/post-new.php?post_type=acf-field-group&wp_ai_fragments_discover=1'
probe_screen '/wp-admin/admin.php?page=wpseo_page_settings&wp_ai_fragments_discover=1'

if [ -n "$contact_form_id" ]; then
	probe_screen "/wp-admin/admin.php?page=wpcf7&post=$contact_form_id&action=edit&wp_ai_fragments_discover=1"
fi

echo "Test data and fragment inventory are ready (WooCommerce product id $product_id; Contact Form 7 forms: $contact_form_count)."
