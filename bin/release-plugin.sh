#!/usr/bin/env bash
# On a version bump: tag vX.Y.Z, build the plugin zip, and publish a GitHub release.
# Local runs refuse to publish unless RELEASE_PLUGIN=1. Tokens stay in the environment.
set -euo pipefail
# A pipeline trace would expand the GitHub remote URL. Keep xtrace off.
set +x

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=plugin-release-lib.sh
source "$ROOT/bin/plugin-release-lib.sh"

MODE="publish"
PUBLISH_CURRENT=0
if [[ "${1:-}" == "--check" ]]; then
  MODE="check"
elif [[ "${1:-}" == "--current" ]]; then
  PUBLISH_CURRENT=1
elif [[ -n "${1:-}" ]]; then
  echo "usage: release-plugin.sh [--check|--current]" >&2
  exit 1
fi

VERSION="$(plugin_header_version "$ROOT/mailblaze-woocommerce-integration.php")"
STABLE="$(plugin_stable_tag "$ROOT/readme.txt")"
PREVIOUS=""
if git -C "$ROOT" rev-parse --verify --quiet HEAD^ >/dev/null; then
  PREVIOUS="$(git -C "$ROOT" show "HEAD^:mailblaze-woocommerce-integration.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -n 1 | tr -d '[:space:]')"
fi

DECISION="$(plugin_release_decision "$VERSION" "$STABLE" "$PREVIOUS")"
if [[ "$DECISION" == "skip" ]]; then
  if [[ "$PUBLISH_CURRENT" == "1" ]]; then
    echo "Version ${VERSION} matches the previous commit. --current continues to GitHub."
  else
    echo "Version ${VERSION} is unchanged. Skipping the GitHub release."
    exit 0
  fi
fi

if [[ "$DECISION" == "release" ]]; then
  echo "Version changed from ${PREVIOUS:-<none>} to ${VERSION}."
fi
if [[ "$MODE" == "check" ]]; then
  echo "release v${VERSION}"
  exit 0
fi

if ! plugin_release_publish_allowed; then
  echo "RELEASE_PLUGIN is not 1. Refusing to tag or create a GitHub release." >&2
  exit 1
fi
if [[ -z "${GITHUB_TOKEN:-}" ]]; then
  echo "GITHUB_TOKEN is required to publish the release." >&2
  exit 1
fi
if [[ -z "${GITHUB_USERNAME:-}" ]]; then
  echo "GITHUB_USERNAME is required to publish the release." >&2
  exit 1
fi

# Git output is passed through plugin_redact_github_auth. The remote URL is
# built only inside this function, with xtrace already off.
run_github_git() {
  set +x
  local output status url args arg
  url="https://${GITHUB_USERNAME}:${GITHUB_TOKEN}@github.com/mailblaze/mailblaze-woocommerce-plugin.git"
  args=()
  for arg in "$@"; do
    if [[ "$arg" == "GITHUB_REMOTE" ]]; then
      args+=("$url")
    else
      args+=("$arg")
    fi
  done
  set +e
  output=$(
    set +x
    unset GIT_TRACE GIT_TRACE_PACKET GIT_TRACE_PERFORMANCE GIT_CURL_VERBOSE GIT_TRACE_CURL GIT_TRACE_SETUP
    export GIT_TERMINAL_PROMPT=0
    git -C "$ROOT" -c credential.helper= "${args[@]}" 2>&1
  )
  status=$?
  set -e
  plugin_redact_github_auth "$output" "$GITHUB_TOKEN" "$GITHUB_USERNAME"
  return "$status"
}

TAG="v${VERSION}"
SHA="$(git -C "$ROOT" rev-parse HEAD)"
ZIP="$ROOT/dist/mailblaze-woocommerce-integration.zip"
python3 "$ROOT/bin/build-plugin-zip.py" "$ZIP" >/dev/null

BODY="$(plugin_changelog_for_version "$ROOT/readme.txt" "$VERSION")"
if [[ -z "${BODY//[[:space:]]/}" ]]; then
  BODY="Mailblaze WooCommerce Integration ${VERSION}"
fi

origin_commit="$(git -C "$ROOT" ls-remote origin "refs/tags/${TAG}^{}" | awk 'NR==1 { print $1 }')"
remote_version=""
if [[ -n "$origin_commit" ]]; then
  if ! git -C "$ROOT" cat-file -e "${origin_commit}^{commit}" 2>/dev/null; then
    git -C "$ROOT" fetch --no-tags origin "$origin_commit"
  fi
  remote_version="$(git -C "$ROOT" show "${origin_commit}:mailblaze-woocommerce-integration.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -n 1 | tr -d '[:space:]')"
fi
origin_action="$(plugin_origin_tag_action "$origin_commit" "$SHA" "$remote_version" "$VERSION" "$PUBLISH_CURRENT")"
RELEASE_SHA="$SHA"
if [[ "$origin_action" == "push" ]]; then
  if git -C "$ROOT" rev-parse --verify --quiet "refs/tags/${TAG}" >/dev/null; then
    EXISTING="$(git -C "$ROOT" rev-parse "${TAG}^{}")"
    if [[ "$EXISTING" != "$SHA" ]]; then
      echo "Tag ${TAG} already points at ${EXISTING}, not ${SHA}." >&2
      exit 1
    fi
  else
    git -C "$ROOT" \
      -c user.name="Mail Blaze Pipelines" \
      -c user.email="pipelines@mailblaze.com" \
      tag -a "$TAG" -m "Mailblaze WooCommerce Integration ${VERSION}" "$SHA"
  fi
  git -C "$ROOT" push origin "refs/tags/${TAG}:refs/tags/${TAG}"
else
  if ! git -C "$ROOT" rev-parse --verify --quiet "refs/tags/${TAG}" >/dev/null; then
    git -C "$ROOT" fetch origin "refs/tags/${TAG}:refs/tags/${TAG}"
  fi
  EXISTING="$(git -C "$ROOT" rev-parse "${TAG}^{}")"
  if [[ "$EXISTING" != "$origin_commit" ]]; then
    echo "Tag ${TAG} already points at ${EXISTING}, not ${origin_commit}." >&2
    exit 1
  fi
  RELEASE_SHA="$origin_commit"
  echo "Tag ${TAG} is already on origin at this commit."
fi

set +e
github_tags="$(run_github_git ls-remote --tags GITHUB_REMOTE "refs/tags/${TAG}")"
github_status=$?
set -e
if [[ -n "$github_tags" ]]; then
  printf '%s\n' "$github_tags"
fi
if [[ "$github_status" -ne 0 ]]; then
  exit "$github_status"
fi
if printf '%s\n' "$github_tags" | grep -q "refs/tags/${TAG}$"; then
  echo "Tag ${TAG} is already on GitHub."
else
  set +e
  github_push="$(run_github_git push GITHUB_REMOTE "refs/tags/${TAG}:refs/tags/${TAG}")"
  github_status=$?
  set -e
  if [[ -n "$github_push" ]]; then
    printf '%s\n' "$github_push"
  fi
  if [[ "$github_status" -ne 0 ]]; then
    exit "$github_status"
  fi
fi

PLUGIN_TAG="$TAG" \
PLUGIN_SHA="$RELEASE_SHA" \
PLUGIN_ZIP="$ZIP" \
PLUGIN_RELEASE_BODY="$BODY" \
python3 "$ROOT/bin/publish-github-release.py"
