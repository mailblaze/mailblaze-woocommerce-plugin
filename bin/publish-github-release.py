#!/usr/bin/env python3
"""Create or complete a GitHub release and upload the plugin zip.

Reads GITHUB_TOKEN from the environment. Prints the release page and asset
name only. Does not print the token or request headers.
"""

import json
import os
import sys
import urllib.error
import urllib.request

REPO = "mailblaze/mailblaze-woocommerce-plugin"
ASSET_NAME = "mailblaze-woocommerce-integration.zip"


def request(token, method, url, data=None, content_type=None):
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Bearer " + token)
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("User-Agent", "mailblaze-woocommerce-release")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    if content_type:
        req.add_header("Content-Type", content_type)
    try:
        with urllib.request.urlopen(req, timeout=120) as response:
            raw = response.read()
            status = response.status
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        status = exc.code
    text = raw.decode("utf-8", "replace")
    if not text:
        return status, None
    try:
        return status, json.loads(text)
    except ValueError:
        return status, text


def fail(status, payload):
    print("GitHub release request failed ({0}).".format(status), file=sys.stderr)
    if isinstance(payload, str):
        print(payload, file=sys.stderr)
    else:
        message = ""
        if isinstance(payload, dict):
            message = payload.get("message") or ""
        print(message or "GitHub returned an error response.", file=sys.stderr)
    return 1


def main():
    token = os.environ.get("GITHUB_TOKEN", "")
    tag = os.environ.get("PLUGIN_TAG", "")
    sha = os.environ.get("PLUGIN_SHA", "")
    body = os.environ.get("PLUGIN_RELEASE_BODY", "")
    zip_path = os.environ.get("PLUGIN_ZIP", "")
    if not token or not tag or not sha or not zip_path:
        print("PLUGIN_TAG, PLUGIN_SHA, PLUGIN_ZIP, and GITHUB_TOKEN are required.", file=sys.stderr)
        return 1
    if not os.path.isfile(zip_path):
        print("Plugin zip does not exist.", file=sys.stderr)
        return 1

    status, release = request(
        token,
        "GET",
        "https://api.github.com/repos/{0}/releases/tags/{1}".format(REPO, tag),
    )
    if status == 404:
        payload = json.dumps(
            {
                "tag_name": tag,
                "target_commitish": sha,
                "name": tag,
                "body": body,
                "draft": False,
                "prerelease": False,
            }
        ).encode("utf-8")
        status, release = request(
            token,
            "POST",
            "https://api.github.com/repos/{0}/releases".format(REPO),
            payload,
            "application/json",
        )
        if status not in (200, 201) or not isinstance(release, dict):
            return fail(status, release)
    elif status != 200 or not isinstance(release, dict):
        return fail(status, release)

    assets = release.get("assets") or []
    if any(asset.get("name") == ASSET_NAME for asset in assets):
        print("Release {0} already has {1}.".format(release.get("html_url"), ASSET_NAME))
        return 0

    with open(zip_path, "rb") as handle:
        data = handle.read()
    upload_url = (
        "https://uploads.github.com/repos/{0}/releases/{1}/assets?name={2}"
    ).format(REPO, release["id"], ASSET_NAME)
    status, uploaded = request(token, "POST", upload_url, data, "application/zip")
    if status not in (200, 201) or not isinstance(uploaded, dict):
        return fail(status, uploaded)

    print("Published {0}: {1} ({2})".format(tag, release.get("html_url"), ASSET_NAME))
    return 0


if __name__ == "__main__":
    sys.exit(main())
