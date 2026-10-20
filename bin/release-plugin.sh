#!/usr/bin/env bash
# On a version bump: tag vX.Y.Z, build the plugin zip, and publish a GitHub release.
# Local runs refuse to publish unless RELEASE_PLUGIN=1. Tokens stay in the environment.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=plugin-release-lib.sh
source "$ROOT/bin/plugin-release-lib.sh"

MODE="publish"
if [[ "${1:-}" == "--check" ]]; then
  MODE="check"
elif [[ -n "${1:-}" ]]; then
  echo "usage: release-plugin.sh [--check]" >&2
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
  echo "Version ${VERSION} is unchanged. Skipping the GitHub release."
  exit 0
fi

echo "Version changed from ${PREVIOUS:-<none>} to ${VERSION}."
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

TAG="v${VERSION}"
SHA="$(git -C "$ROOT" rev-parse HEAD)"
ZIP="$ROOT/dist/mailblaze-woocommerce-integration.zip"
python3 "$ROOT/bin/build-plugin-zip.py" "$ZIP" >/dev/null

BODY="$(plugin_changelog_for_version "$ROOT/readme.txt" "$VERSION")"
if [[ -z "${BODY//[[:space:]]/}" ]]; then
  BODY="Mailblaze WooCommerce Integration ${VERSION}"
fi

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

if git -C "$ROOT" ls-remote --tags origin "refs/tags/${TAG}" | grep -q .; then
  echo "Tag ${TAG} is already on origin."
else
  git -C "$ROOT" push origin "refs/tags/${TAG}:refs/tags/${TAG}"
fi

GITHUB_REPO="https://github.com/mailblaze/mailblaze-woocommerce-plugin.git"
if git -C "$ROOT" -c "http.extraheader=AUTHORIZATION: bearer ${GITHUB_TOKEN}" \
  ls-remote --tags "$GITHUB_REPO" "refs/tags/${TAG}" | grep -q .; then
  echo "Tag ${TAG} is already on GitHub."
else
  git -C "$ROOT" -c "http.extraheader=AUTHORIZATION: bearer ${GITHUB_TOKEN}" \
    push "$GITHUB_REPO" "refs/tags/${TAG}:refs/tags/${TAG}"
fi

PLUGIN_TAG="$TAG" \
PLUGIN_SHA="$SHA" \
PLUGIN_ZIP="$ZIP" \
PLUGIN_RELEASE_BODY="$BODY" \
python3 "$ROOT/bin/publish-github-release.py"
