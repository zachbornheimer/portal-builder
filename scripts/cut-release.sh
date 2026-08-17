#!/usr/bin/env bash
# Tag the plugin semver, build the zip, optionally publish a GitHub Release.
#
#   mise run cut-release
#   VERSION=0.1.7 mise run cut-release
#   CUT_RELEASE_PUSH=1 mise run cut-release
#
# If VERSION is unset and v{current} is not tagged, keep the current Version
# (use this after a version bump is already committed). Otherwise patch-bump.
# Never moves an existing tag. Never force-pushes.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "cut-release: not a git repository" >&2
  exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
branch="${branch#heads/}"
if [[ "${branch}" != "main" && "${CUT_RELEASE_ALLOW_BRANCH:-}" != "1" ]]; then
  echo "cut-release: must run on main (got ${branch}); set CUT_RELEASE_ALLOW_BRANCH=1 to override" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain --untracked-files=no)" && "${CUT_RELEASE_ALLOW_DIRTY:-}" != "1" ]]; then
  echo "cut-release: working tree dirty. Commit first, or set CUT_RELEASE_ALLOW_DIRTY=1" >&2
  git status --short --untracked-files=no >&2
  exit 1
fi

current="$(grep 'Version:' portal-builder.php | awk '{print $3}')"
if [[ ! "${current}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "cut-release: plugin Version is not semver: ${current}" >&2
  exit 1
fi

next="${VERSION:-}"
if [[ -z "${next}" ]]; then
  if ! git rev-parse -q --verify "refs/tags/v${current}" >/dev/null 2>&1; then
    next="${current}"
  elif [[ "${current}" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    next="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
  else
    echo "cut-release: pass VERSION=X.Y.Z" >&2
    exit 1
  fi
fi

if [[ ! "${next}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "cut-release: VERSION must be MAJOR.MINOR.PATCH (got ${next})" >&2
  exit 1
fi

tag="v${next}"
if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null 2>&1; then
  echo "cut-release: tag ${tag} already exists — refuse to move it" >&2
  exit 1
fi

echo "cut-release: ${current} → ${next} (${tag})"

if [[ "${next}" != "${current}" ]]; then
  tmp="$(mktemp)"
  sed \
    -e "s/^ \\* Version:     ${current}$/ * Version:     ${next}/" \
    -e "s/define( 'DG_VERSION', '${current}' );/define( 'DG_VERSION', '${next}' );/" \
    portal-builder.php >"${tmp}"
  mv "${tmp}" portal-builder.php
  if ! grep -q "Version:     ${next}" portal-builder.php || ! grep -q "DG_VERSION', '${next}'" portal-builder.php; then
    echo "cut-release: failed to bump portal-builder.php to ${next}" >&2
    exit 1
  fi
fi

mise run test
mise run build

zip_path="${ROOT}/dist/portal-builder-${next}.zip"
if [[ ! -f "${zip_path}" ]]; then
  echo "cut-release: expected zip missing: ${zip_path}" >&2
  exit 1
fi

if [[ "${next}" != "${current}" ]]; then
  git add portal-builder.php
  git commit -m "chore(release): ${next}"
fi

git tag -a "${tag}" -m "${tag}"
echo "cut-release: tagged ${tag} at $(git rev-parse --short HEAD)"
echo "cut-release: zip ${zip_path}"

if [[ "${CUT_RELEASE_PUSH:-0}" == "1" ]]; then
  git push origin refs/heads/main
  git push origin "refs/tags/${tag}"
  if command -v gh >/dev/null 2>&1; then
    gh release create "${tag}" "${zip_path}" --title "${tag}" --generate-notes
  else
    echo "cut-release: gh not installed; tag pushed, no GitHub Release" >&2
  fi
else
  echo "cut-release: skip push (set CUT_RELEASE_PUSH=1 to publish)"
fi

echo "cut-release: done ${tag}"
