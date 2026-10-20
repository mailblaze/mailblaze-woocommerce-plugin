#!/usr/bin/env bash
# Helpers for deciding whether a commit should publish a plugin release.
# This file does not talk to GitHub and does not read tokens.

plugin_header_version() {
  local file="$1"
  sed -n 's/^ \* Version:[[:space:]]*//p' "$file" | head -n 1 | tr -d '[:space:]'
}

plugin_stable_tag() {
  local file="$1"
  sed -n 's/^Stable tag:[[:space:]]*//p' "$file" | head -n 1 | tr -d '[:space:]'
}

plugin_version_is_release() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

# Prints "release" or "skip" on stdout.
# Returns 1 and writes the reason to stderr when the versions disagree or are not X.Y.Z.
plugin_release_decision() {
  local version="$1"
  local stable_tag="$2"
  local previous="$3"

  if ! plugin_version_is_release "$version"; then
    echo "Plugin header version is not a plain X.Y.Z release: ${version}" >&2
    return 1
  fi
  if ! plugin_version_is_release "$stable_tag"; then
    echo "readme.txt Stable tag is not a plain X.Y.Z release: ${stable_tag}" >&2
    return 1
  fi
  if [[ "$version" != "$stable_tag" ]]; then
    echo "readme.txt Stable tag ${stable_tag} does not match plugin version ${version}" >&2
    return 1
  fi
  if [[ -n "$previous" ]] && ! plugin_version_is_release "$previous"; then
    echo "Previous plugin version is not a plain X.Y.Z release: ${previous}" >&2
    return 1
  fi
  if [[ "$version" == "$previous" ]]; then
    echo skip
    return 0
  fi
  echo release
}

plugin_release_publish_allowed() {
  [[ "${RELEASE_PLUGIN:-}" == "1" ]]
}

plugin_changelog_for_version() {
  local readme="$1"
  local version="$2"
  awk -v ver="$version" '
    $0 == "= " ver " =" { capture = 1; next }
    capture && /^= / { exit }
    capture { print }
  ' "$readme"
}
