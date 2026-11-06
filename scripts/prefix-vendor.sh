#!/usr/bin/env bash
# Copy the production Composer dependencies into vendor-prefixed/ under the
# DragonGate\Vendor\ namespace (and DragonGate_Vendor_ for global classes) so
# they cannot collide with another plugin's copy of Guzzle, psr/log, Monolog,
# the Google client, or PHPMailer. Whichever plugin autoloads first otherwise
# wins, which fataled isjac.org on submit (ZYS-2607).
#
# Strauss reads composer.json extra.strauss and vendor/composer/installed.json,
# so run this after `composer install`. Composer calls it from the
# post-install-cmd / post-update-cmd hooks; run it by hand with
# `composer prefix-vendor`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

STRAUSS_VERSION="0.31.1"
STRAUSS_SHA256="17dc2baf27b9a3f52834e3db729c8365d8915a80e37a087d253cf705387aa887"
STRAUSS_URL="https://github.com/BrianHenryIE/strauss/releases/download/${STRAUSS_VERSION}/strauss.phar"
STRAUSS_PHAR="${ROOT}/bin/strauss.phar"

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	else
		shasum -a 256 "$1" | awk '{print $1}'
	fi
}

if [[ ! -f "${STRAUSS_PHAR}" || "$(sha256_of "${STRAUSS_PHAR}")" != "${STRAUSS_SHA256}" ]]; then
	mkdir -p "$(dirname "${STRAUSS_PHAR}")"
	echo "prefix-vendor: downloading strauss ${STRAUSS_VERSION}" >&2
	curl -fsSL -o "${STRAUSS_PHAR}" "${STRAUSS_URL}"
	got="$(sha256_of "${STRAUSS_PHAR}")"
	if [[ "${got}" != "${STRAUSS_SHA256}" ]]; then
		rm -f "${STRAUSS_PHAR}"
		echo "prefix-vendor: strauss.phar sha256 ${got} != pinned ${STRAUSS_SHA256} (${STRAUSS_URL})" >&2
		exit 1
	fi
fi

if [[ ! -f vendor/composer/installed.json ]]; then
	echo "prefix-vendor: vendor/composer/installed.json missing; run composer install first" >&2
	exit 1
fi

# Start from a clean target so a dropped dependency cannot linger in the zip.
rm -rf vendor-prefixed

php -d memory_limit=1G "${STRAUSS_PHAR}"

for required in vendor-prefixed/autoload.php vendor-prefixed/google/apiclient/src/Client.php; do
	if [[ ! -f "${required}" ]]; then
		echo "prefix-vendor: strauss did not produce ${required}" >&2
		exit 1
	fi
done

# Strauss rewrites namespaces but not these two string forms.
#
# 1. Every generated Google service class ends with
#    class_alias( Foo::class, 'Google_Service_Drive_Foo' ). Those global
#    aliases are exactly the shared names that collide with another plugin's
#    copy (PHP warns and keeps the first), and the plugin uses the namespaced
#    classes only. Drop them.
# 2. Monolog names a few callables as 'Monolog\Logger::method' strings, which
#    would resolve to another plugin's Monolog. Point them at our copy.
find vendor-prefixed/google/apiclient-services -name '*.php' -print0 |
	xargs -0 perl -ni -e 'print unless /^class_alias\(.*\x27Google_Service_\w+\x27\);\s*$/'
find vendor-prefixed/monolog -name '*.php' -print0 |
	xargs -0 perl -pi -e 's/\x27Monolog\\Logger::/\x27DragonGate\\Vendor\\Monolog\\Logger::/g'

# Leftover global aliases would reintroduce the collision.
if grep -rqE "^class_alias\(.*'Google_" vendor-prefixed --include='*.php'; then
	echo "prefix-vendor: Google_* class_alias survived in vendor-prefixed/" >&2
	exit 1
fi

# Nothing may keep an unprefixed namespace declaration. Cheapest proof the
# rewrite covered every package.
leftover="$(grep -rlE '^namespace (GuzzleHttp|Monolog|Psr|Google|Firebase|PHPMailer)\\' vendor-prefixed --include='*.php' || true)"
if [[ -n "${leftover}" ]]; then
	echo "prefix-vendor: unprefixed namespace declaration remains in vendor-prefixed/:" >&2
	echo "${leftover}" | head -5 >&2
	exit 1
fi

# Upstream documentation is not runtime code and does not belong in the shipped
# zip. firebase/php-jwt's README embeds a sample PEM private key, which a
# secrets scan of the zip rightly reports. LICENSE files stay: redistribution
# requires them.
find vendor-prefixed -type f \( -iname 'README*' -o -iname 'CHANGELOG*' -o -iname 'UPGRADING*' -o -iname 'CONTRIBUTING*' \) -delete

echo "prefix-vendor: vendor-prefixed/ ready"
