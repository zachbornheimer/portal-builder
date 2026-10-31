#!/usr/bin/env bash
# Build dist/portal-builder-<semver>.zip from HEAD + vendor + built assets.
# A fresh clone leaves submodule paths as empty directories. git archive
# ships that empty directory, and WordPress fatals on the first require.
# Initialise missing checkouts, then refuse to zip if a load-time require
# is still absent from the tree or the zip.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

VERSION="$(grep 'Version:' portal-builder.php | awk '{print $3}')"
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "plugin Version is not semver: ${VERSION}" >&2
	exit 1
fi

# Clone submodules a fresh clone left empty. Do not update an
# already-checked-out submodule: that would restore a deleted required
# file and hide a broken tree. The assertion below must fail instead.
if [[ -f .gitmodules ]]; then
	while read -r status path _; do
		[[ -z "${status:-}" ]] && continue
		if [[ "${status}" == -* ]]; then
			echo "make-release-zip: initialising empty submodule ${path}" >&2
			if ! git submodule update --init --recursive -- "${path}"; then
				echo "make-release-zip: submodule init failed for ${path}" >&2
			fi
		fi
	done < <(git submodule status --recursive)
fi

# Top-level requires only. portal-builder.php loads these at file scope;
# a missing path fatals every request.
missing=0
while IFS= read -r rel; do
	[[ -z "${rel}" ]] && continue
	if [[ ! -f "${rel}" ]]; then
		echo "make-release-zip: required file missing: ${rel}" >&2
		echo "make-release-zip: portal-builder.php requires it at load. An empty submodule cannot be shipped (git submodule update --init --recursive); vendor/ needs composer install --no-dev." >&2
		missing=1
	fi
done < <(grep -E '^require(_once)?[[:space:]]+' portal-builder.php | sed -nE "s/.*plugin_dir_path\\( __FILE__ \\) \\. '([^']+)'.*/\\1/p")

if [[ "${missing}" -ne 0 ]]; then
	echo "make-release-zip: refusing to zip a tree that would fatal on plugin load" >&2
	exit 1
fi

mkdir -p dist
OUT="${ROOT}/dist/portal-builder-${VERSION}.zip"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/dg-release.XXXXXX")"
cleanup() { rm -rf "${TMP}"; }
trap cleanup EXIT

PREFIX="dragongate-portals"
git archive --format=tar --prefix="${PREFIX}/" HEAD | tar -C "${TMP}" -xf -

git submodule foreach --quiet --recursive '
	sm_path="$displaypath"
	git archive --format=tar --prefix="'"${PREFIX}"'/${sm_path}/" HEAD | tar -C "'"${TMP}"'" -xf -
'

if [[ -d vendor ]]; then
	mkdir -p "${TMP}/${PREFIX}/vendor"
	cp -R vendor/. "${TMP}/${PREFIX}/vendor/"
fi
if [[ -d assets/dist ]]; then
	mkdir -p "${TMP}/${PREFIX}/assets/dist"
	cp -R assets/dist/. "${TMP}/${PREFIX}/assets/dist/"
fi
# git archive honors export-ignore; the plugin still needs the notes for
# the Updates "View details" changelog when GitHub's API is blocked.
if [[ -f CHANGELOG.md ]]; then
	cp CHANGELOG.md "${TMP}/${PREFIX}/CHANGELOG.md"
fi

rm -f "${OUT}"
(
	cd "${TMP}"
	zip -qr "${OUT}" "${PREFIX}"
)

zip_names="$(unzip -Z1 "${OUT}")"
zip_missing=0
require_zip_member() {
	local member="$1"
	# Match the saved listing. Do not pipe unzip into grep -q: grep exits
	# early, unzip dies with SIGPIPE, and pipefail reports a hit as missing.
	if ! grep -qx "${PREFIX}/${member}" <<<"${zip_names}"; then
		echo "make-release-zip: zip missing required member ${PREFIX}/${member}" >&2
		zip_missing=1
	fi
}

require_zip_member "includes/adapters/class-zysys-file-store.php"
require_zip_member "vendor/autoload.php"
require_zip_member "vendor/google/apiclient/src/Client.php"
while IFS= read -r rel; do
	[[ -z "${rel}" ]] && continue
	require_zip_member "${rel}"
done < <(grep -E '^require(_once)?[[:space:]]+' portal-builder.php | sed -nE "s/.*plugin_dir_path\\( __FILE__ \\) \\. '([^']+)'.*/\\1/p")

echo "make-release-zip: required zip members:" >&2
unzip -l "${OUT}" | awk -v prefix="${PREFIX}/" '
  $NF == prefix "includes/adapters/class-zysys-file-store.php" ||
  $NF == prefix "vendor/autoload.php" ||
  $NF == prefix "vendor/google/apiclient/src/Client.php" {
    print
  }
' >&2

if [[ "${zip_missing}" -ne 0 ]]; then
	echo "make-release-zip: refusing to publish an incomplete zip" >&2
	echo "make-release-zip: full zip listing:" >&2
	unzip -l "${OUT}" >&2
	rm -f "${OUT}"
	exit 1
fi

echo "${OUT}"
echo "version=${VERSION}"
