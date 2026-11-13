#!/usr/bin/env bash
# Bump plugin semver, run the gate, write dist/portal-builder-<ver>.zip.
# Never moves an existing tag. Never force-pushes. Does not deploy.
#
#   mise run cut-release
#   VERSION=0.1.7 mise run cut-release
#
# Push + GitHub Release only when CUT_RELEASE_PUSH=1 (default 0).
set -euo pipefail

readonly PLUGIN_BOOTSTRAP="portal-builder.php"
readonly ALLOW_BRANCH="${CUT_RELEASE_ALLOW_BRANCH:-0}"
readonly ALLOW_DIRTY="${CUT_RELEASE_ALLOW_DIRTY:-0}"
readonly DO_PUSH="${CUT_RELEASE_PUSH:-0}"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${root}"

die() {
  echo "cut-release: $*" >&2
  exit 1
}

require_git_repo() {
  git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "not a git repository"
}

require_main_branch() {
  local branch
  branch="$(git rev-parse --abbrev-ref HEAD)"
  if [[ "${branch}" != "main" && "${ALLOW_BRANCH}" != "1" ]]; then
    die "must run on main (got ${branch}); set CUT_RELEASE_ALLOW_BRANCH=1 to override"
  fi
}

require_clean_tree() {
  if [[ -n "$(git status --porcelain)" && "${ALLOW_DIRTY}" != "1" ]]; then
    echo "cut-release: working tree dirty. Commit first, or set CUT_RELEASE_ALLOW_DIRTY=1" >&2
    git status --short >&2
    exit 1
  fi
}

header_version() {
  sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
    "${PLUGIN_BOOTSTRAP}" | head -1
}

constant_version() {
  sed -nE "s/.*define\([[:space:]]*'DG_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\).*/\1/p" \
    "${PLUGIN_BOOTSTRAP}" | head -1
}

read_plugin_version() {
  local header constant
  header="$(header_version)"
  constant="$(constant_version)"
  if [[ -z "${header}" || -z "${constant}" ]]; then
    die "could not read Version header and DG_VERSION from ${PLUGIN_BOOTSTRAP}"
  fi
  if [[ "${header}" != "${constant}" ]]; then
    die "Version header (${header}) does not match DG_VERSION (${constant})"
  fi
  if [[ ! "${header}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "plugin Version is not X.Y.Z semver: ${header}"
  fi
  printf '%s\n' "${header}"
}

normalize_version() {
  local raw="${1}"
  printf '%s\n' "${raw#v}"
}

next_version() {
  local current="${1}"
  local requested="${VERSION:-}"
  local next major minor patch
  if [[ -n "${requested}" ]]; then
    next="$(normalize_version "${requested}")"
  else
    if [[ ! "${current}" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
      die "current ${current} is not X.Y.Z; pass VERSION=..."
    fi
    major="${BASH_REMATCH[1]}"
    minor="${BASH_REMATCH[2]}"
    patch="${BASH_REMATCH[3]}"
    next="${major}.${minor}.$((patch + 1))"
  fi
  if [[ ! "${next}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "VERSION must be X.Y.Z (got ${next})"
  fi
  if [[ "${next}" == "${current}" ]]; then
    die "next version ${next} is already the plugin Version"
  fi
  printf '%s\n' "${next}"
}

refuse_existing_tag() {
  local tag="${1}"
  if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null 2>&1; then
    die "tag ${tag} already exists — refuse to move it"
  fi
}

write_plugin_version() {
  local next="${1}"
  local tmp
  tmp="$(mktemp)"
  sed -E \
    -e "s/^([[:space:]]*\\*[[:space:]]*Version:[[:space:]]+)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${next}/" \
    -e "s/(define\\([[:space:]]*'DG_VERSION'[[:space:]]*,[[:space:]]*')[0-9]+\\.[0-9]+\\.[0-9]+(')/\\1${next}\\2/" \
    "${PLUGIN_BOOTSTRAP}" >"${tmp}"
  mv "${tmp}" "${PLUGIN_BOOTSTRAP}"
  if [[ "$(header_version)" != "${next}" || "$(constant_version)" != "${next}" ]]; then
    die "failed to write Version ${next} into ${PLUGIN_BOOTSTRAP}"
  fi
}

commit_version() {
  local next="${1}"
  if git diff --quiet -- "${PLUGIN_BOOTSTRAP}"; then
    die "version write produced no diff in ${PLUGIN_BOOTSTRAP}"
  fi
  git add -- "${PLUGIN_BOOTSTRAP}"
  git commit -m "chore(release): ${next}"
}

zip_path_for() {
  local next="${1}"
  printf '%s\n' "${root}/dist/portal-builder-${next}.zip"
}

maybe_publish() {
  local tag="${1}"
  local zip_path="${2}"
  if [[ "${DO_PUSH}" != "1" ]]; then
    echo "cut-release: skip push (CUT_RELEASE_PUSH=${DO_PUSH})"
    return 0
  fi
  command -v gh >/dev/null 2>&1 || die "gh is required when CUT_RELEASE_PUSH=1"
  git push origin HEAD
  git push origin "refs/tags/${tag}"
  gh release create "${tag}" "${zip_path}" --title "${tag}" --generate-notes
}

main() {
  local current next tag zip_path
  require_git_repo
  require_main_branch
  require_clean_tree

  current="$(read_plugin_version)"
  next="$(next_version "${current}")"
  tag="v${next}"
  refuse_existing_tag "${tag}"

  echo "cut-release: ${current} → ${next} (tag ${tag})"
  write_plugin_version "${next}"

  # Test against the bumped working tree. Commit before build so
  # scripts/make-release-zip.sh (git archive HEAD) packs the new Version.
  mise run test
  commit_version "${next}"
  mise run build

  zip_path="$(zip_path_for "${next}")"
  [[ -f "${zip_path}" ]] || die "build did not write ${zip_path}"

  git tag -a "${tag}" -m "${tag}"
  echo "cut-release: tagged ${tag} at $(git rev-parse --short HEAD)"

  maybe_publish "${tag}" "${zip_path}"
  echo "cut-release: zip ${zip_path}"
}

main "$@"
