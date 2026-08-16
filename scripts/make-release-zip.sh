#!/usr/bin/env bash
# Build dist/portal-builder-<semver>.zip from HEAD + vendor + built assets.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

VERSION="$(grep 'Version:' portal-builder.php | awk '{print $3}')"
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "plugin Version is not semver: ${VERSION}" >&2
	exit 1
fi

mkdir -p dist
OUT="${ROOT}/dist/portal-builder-${VERSION}.zip"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/dg-release.XXXXXX")"
cleanup() { rm -rf "${TMP}"; }
trap cleanup EXIT

PREFIX="portal-builder"
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

rm -f "${OUT}"
(
	cd "${TMP}"
	zip -qr "${OUT}" "${PREFIX}"
)

echo "${OUT}"
echo "version=${VERSION}"
