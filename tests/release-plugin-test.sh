#!/usr/bin/env bash
# Version decision, release refusal, and a clean plugin zip. No GitHub writes.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=../bin/plugin-release-lib.sh
source "$ROOT/bin/plugin-release-lib.sh"

fail() {
  echo "$1" >&2
  exit 1
}

decision="$(plugin_release_decision 1.1.3 1.1.3 1.1.2)"
[[ "$decision" == "release" ]] || fail "1.1.3 after 1.1.2 should release"

decision="$(plugin_release_decision 1.1.3 1.1.3 1.1.3)"
[[ "$decision" == "skip" ]] || fail "an unchanged version should skip"

if plugin_release_decision 1.1.3 1.2.0 1.1.2 >/dev/null 2>&1; then
  fail "mismatched stable tag should fail"
fi
if plugin_release_decision 1.2.0-beta 1.2.0-beta "" >/dev/null 2>&1; then
  fail "a pre-release version should fail"
fi

tree_version="$(plugin_header_version "$ROOT/mailblaze-woocommerce-integration.php")"
tree_stable="$(plugin_stable_tag "$ROOT/readme.txt")"
[[ -n "$tree_version" ]] || fail "plugin header has no version"
plugin_version_is_release "$tree_version" || fail "header ${tree_version} is not X.Y.Z"
[[ "$tree_stable" == "$tree_version" ]] || fail "stable tag ${tree_stable} must match header ${tree_version}"
unchanged="$(plugin_release_decision "$tree_version" "$tree_stable" "$tree_version")"
[[ "$unchanged" == "skip" ]] || fail "header ${tree_version} should be a comparable release version"

if RELEASE_PLUGIN= plugin_release_publish_allowed; then
  fail "publish must stay off unless RELEASE_PLUGIN=1"
fi
if ! RELEASE_PLUGIN=1 plugin_release_publish_allowed; then
  fail "RELEASE_PLUGIN=1 should allow the pipeline to publish"
fi

same_tag="$(plugin_origin_tag_action "$tree_version-sha" "$tree_version-sha")"
[[ "$same_tag" == "skip" ]] || fail "a tag that already points at this commit should be left in place"
missing_tag="$(plugin_origin_tag_action "" "$tree_version-sha")"
[[ "$missing_tag" == "push" ]] || fail "a missing origin tag should be pushed"
if plugin_origin_tag_action "other-sha" "this-sha" >/dev/null 2>&1; then
  fail "a tag pointing at another commit should fail"
fi
reused="$(plugin_origin_tag_action "tagged-sha" "later-sha" "1.2.0" "1.2.0" 1)"
[[ "$reused" == "reuse" ]] || fail "a current-version rerun should keep the existing tag"
if plugin_origin_tag_action "tagged-sha" "later-sha" "1.1.2" "1.2.0" 1 >/dev/null 2>&1; then
  fail "a current-version rerun must not reuse a tag from another version"
fi
if plugin_origin_tag_action "tagged-sha" "later-sha" "1.2.0" "1.2.0" 0 >/dev/null 2>&1; then
  fail "a normal release must not move an existing tag onto a new commit"
fi

sample='fatal: unable to access '\''https://release-user:ghp_test_token_should_not_appear@github.com/mailblaze/mailblaze-woocommerce-plugin.git/'\'': 401'
redacted="$(plugin_redact_github_auth "$sample" "ghp_test_token_should_not_appear" "release-user")"
if printf '%s\n' "$redacted" | grep -q 'ghp_test_token_should_not_appear'; then
  fail "redaction left the token in git output"
fi
if printf '%s\n' "$redacted" | grep -q 'release-user:'; then
  fail "redaction left the username and token in the URL"
fi

if grep -q 'extraheader' "$ROOT/bin/release-plugin.sh"; then
  fail "GitHub git calls must not use the bearer extraheader"
fi
if ! grep -q 'https://${GITHUB_USERNAME}:${GITHUB_TOKEN}@github.com/mailblaze/mailblaze-woocommerce-plugin.git' "$ROOT/bin/release-plugin.sh"; then
  fail "GitHub git calls must use the username and token URL"
fi
if ! grep -q 'release-current-version' "$ROOT/bitbucket-pipelines.yml"; then
  fail "a manual pipeline must be able to publish the current version"
fi

set +e
check_output="$(bash "$ROOT/bin/release-plugin.sh" --check 2>&1)"
check_status=$?
set -e
[[ "$check_status" -eq 0 ]] || fail "--check failed: ${check_output}"

tags_before="$(git -C "$ROOT" tag -l 'v*')"
set +e
guard_output="$(env -u RELEASE_PLUGIN -u GITHUB_TOKEN bash "$ROOT/bin/release-plugin.sh" 2>&1)"
guard_status=$?
set -e
if [[ "$guard_status" -ne 0 && "$guard_status" -ne 1 ]]; then
  fail "release script exited ${guard_status}: ${guard_output}"
fi
if printf '%s\n' "$guard_output" | grep -q 'Published '; then
  fail "the local run published a release: ${guard_output}"
fi
if [[ "$(git -C "$ROOT" tag -l 'v*')" != "$tags_before" ]]; then
  fail "a local run created a version tag"
fi

leak_marker='ghp_test_token_should_not_appear'
set +e
leak_output="$(GITHUB_TOKEN="$leak_marker" GITHUB_USERNAME='release-user' bash -x "$ROOT/bin/release-plugin.sh" --current 2>&1)"
leak_status=$?
set -e
if [[ "$leak_status" -eq 0 ]]; then
  fail "--current without RELEASE_PLUGIN should refuse"
fi
if printf '%s\n' "$leak_output" | grep -q 'Published '; then
  fail "--current published a release"
fi
if printf '%s\n' "$leak_output" | grep -q "$leak_marker"; then
  fail "bash -x printed the GitHub token"
fi
if [[ "$(git -C "$ROOT" tag -l 'v*')" != "$tags_before" ]]; then
  fail "--current created a version tag"
fi

ZIP="$(mktemp /tmp/mailblaze-woocommerce-integration.XXXXXX.zip)"
python3 "$ROOT/bin/build-plugin-zip.py" "$ZIP" >/dev/null
listing="$(python3 - "$ZIP" <<'PY'
import sys, zipfile
archive = zipfile.ZipFile(sys.argv[1])
print("\n".join(archive.namelist()))
PY
)"

require_name() {
  printf '%s\n' "$listing" | grep -qx "$1" || fail "zip missing $1"
}
reject_prefix() {
  if printf '%s\n' "$listing" | grep -q "^$1"; then
    fail "zip contains $1"
  fi
}

require_name "mailblaze-woocommerce-integration/mailblaze-woocommerce-integration.php"
require_name "mailblaze-woocommerce-integration/readme.txt"
require_name "mailblaze-woocommerce-integration/includes/update-checker.php"
require_name "mailblaze-woocommerce-integration/plugin-update-checker/plugin-update-checker.php"
require_name "mailblaze-woocommerce-integration/plugin-update-checker/license.txt"
reject_prefix "mailblaze-woocommerce-integration/tests/"
reject_prefix "mailblaze-woocommerce-integration/bin/"
reject_prefix "mailblaze-woocommerce-integration/bitbucket-pipelines.yml"
reject_prefix "mailblaze-woocommerce-integration/.git"

header="$(python3 - "$ZIP" <<'PY'
import sys, zipfile
archive = zipfile.ZipFile(sys.argv[1])
print(archive.read("mailblaze-woocommerce-integration/mailblaze-woocommerce-integration.php").decode())
print("---README---")
print(archive.read("mailblaze-woocommerce-integration/readme.txt").decode())
PY
)"
printf '%s\n' "$header" | grep -q "Version:     ${tree_version}" || fail "zip header is not ${tree_version}"
printf '%s\n' "$header" | grep -q "Stable tag: ${tree_version}" || fail "zip stable tag is not ${tree_version}"
rm -f "$ZIP"

echo "ok"
