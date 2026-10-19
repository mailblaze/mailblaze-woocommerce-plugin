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

base_version="$(git -C "$ROOT" show origin/master:mailblaze-woocommerce-integration.php | sed -n 's/^ \* Version:[[:space:]]*//p' | head -n 1 | tr -d '[:space:]')"
tree_version="$(plugin_header_version "$ROOT/mailblaze-woocommerce-integration.php")"
tree_stable="$(plugin_stable_tag "$ROOT/readme.txt")"
[[ "$tree_version" == "1.1.2" ]] || fail "header must stay 1.1.2, got ${tree_version}"
[[ "$tree_stable" == "$tree_version" ]] || fail "stable tag ${tree_stable} must match header ${tree_version}"
[[ "$base_version" == "$tree_version" ]] || fail "this branch must not move the version off origin/master (${base_version})"
unchanged="$(plugin_release_decision "$tree_version" "$tree_stable" "$base_version")"
[[ "$unchanged" == "skip" ]] || fail "version ${tree_version} against origin/master should skip"

if RELEASE_PLUGIN= plugin_release_publish_allowed; then
  fail "publish must stay off unless RELEASE_PLUGIN=1"
fi
if ! RELEASE_PLUGIN=1 plugin_release_publish_allowed; then
  fail "RELEASE_PLUGIN=1 should allow the pipeline to publish"
fi

set +e
check_output="$(bash "$ROOT/bin/release-plugin.sh" --check 2>&1)"
check_status=$?
set -e
[[ "$check_status" -eq 0 ]] || fail "--check failed: ${check_output}"

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
if git -C "$ROOT" tag -l 'v*' | grep -q .; then
  fail "a local run created a version tag"
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
printf '%s\n' "$header" | grep -q 'Version:     1.1.2' || fail "zip header is not 1.1.2"
printf '%s\n' "$header" | grep -q 'Stable tag: 1.1.2' || fail "zip stable tag is not 1.1.2"
rm -f "$ZIP"

echo "ok"
