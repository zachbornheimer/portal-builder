#!/usr/bin/env bash
# Push DragonGate Portals to the ISJAC WP Engine install.
# Usage: scripts/push-plugin.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${DG_PUSH_REMOTE:-isjac@isjac.ssh.wpengine.net}"
# Live dest folder is still named portal-builder-0.0.4a; do not retarget on a version bump.
DEST="${DG_PUSH_DEST:-/sites/isjac/wp-content/plugins/portal-builder-0.0.4a/}"

# Public form loads assets/tokens.css. Keep it identical to the token source.
cp "${ROOT}/src/tokens.css" "${ROOT}/assets/tokens.css"

if [[ "${DG_PUSH_BUILD:-1}" == "1" ]]; then
	(cd "${ROOT}" && npm run build)
fi

rsync -az --omit-dir-times \
	--exclude '.git/' \
	--exclude 'node_modules/' \
	--exclude 'tests/' \
	--exclude 'e2e/' \
	--exclude 'src/' \
	--exclude 'docs/' \
	--exclude 'suggested-refactoring.md' \
	--exclude 'playwright.config.ts' \
	--exclude 'package.json' \
	--exclude 'package-lock.json' \
	--exclude 'vite.config.js' \
	--exclude 'tailwind.config.js' \
	--exclude 'postcss.config.js' \
	--exclude '.gitignore' \
	--exclude '.github/' \
	--exclude 'gsuite-filestore/vendor/' \
	--exclude 'gsuite-filestore/.git/' \
	--include 'portal-builder.php' \
	--include 'includes/***' \
	--include 'assets/***' \
	--include 'gsuite-filestore/' \
	--include 'gsuite-filestore/***' \
	--include 'vendor/' \
	--include 'vendor/***' \
	--exclude '*' \
	"${ROOT}/" "${REMOTE}:${DEST}"

echo "pushed to ${REMOTE}:${DEST}"
