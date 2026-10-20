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

# Prints "push" when origin has no tag, "skip" when that tag already points
# at this commit, or "reuse" when --current is republishing the same version
# from the existing tag. Returns 1 when the tag must not be moved.
plugin_origin_tag_action() {
  local remote_commit=$1
  local head_commit=$2
  local remote_version=${3:-}
  local version=${4:-}
  local publish_current=${5:-0}
  if [[ -z "$remote_commit" ]]; then
    echo push
    return 0
  fi
  if [[ "$remote_commit" == "$head_commit" ]]; then
    echo skip
    return 0
  fi
  if [[ "$publish_current" == "1" && -n "$remote_version" && "$remote_version" == "$version" ]]; then
    echo reuse
    return 0
  fi
  echo "Tag on origin points at ${remote_commit}, not ${head_commit}." >&2
  return 1
}

# Removes a GitHub token from text that git may have echoed. Callers pass the
# values; this function does not read the environment.
plugin_redact_github_auth() {
  set +x
  local text=$1
  local token=${2:-}
  local username=${3:-}
  if [[ -n "$token" ]]; then
    text=${text//"$token"/***}
  fi
  if [[ -n "$username" ]]; then
    text=${text//"${username}:"/***:}
  fi
  printf '%s\n' "$text" | sed -E 's#https://[^[:space:]@]+@#https://***@#g'
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
