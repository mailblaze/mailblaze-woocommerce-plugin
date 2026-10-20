#!/usr/bin/env python3
"""Build the WordPress plugin zip. Development and CI files stay out of it."""

import os
import sys
import zipfile

SLUG = "mailblaze-woocommerce-integration"
EXCLUDED_DIR_NAMES = {
    ".git",
    ".github",
    "bin",
    "dist",
    "tests",
    "__pycache__",
}
EXCLUDED_FILE_NAMES = {
    ".gitignore",
    ".gitattributes",
    "bitbucket-pipelines.yml",
    ".DS_Store",
}
REQUIRED = (
    SLUG + "/mailblaze-woocommerce-integration.php",
    SLUG + "/readme.txt",
    SLUG + "/includes/update-checker.php",
    SLUG + "/plugin-update-checker/plugin-update-checker.php",
    SLUG + "/plugin-update-checker/license.txt",
)


def main():
    if len(sys.argv) != 2:
        print("usage: build-plugin-zip.py OUTPUT.zip", file=sys.stderr)
        return 1

    root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    output = os.path.abspath(sys.argv[1])
    os.makedirs(os.path.dirname(output), exist_ok=True)

    names = []
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = sorted(
                name for name in dirnames if name not in EXCLUDED_DIR_NAMES
            )
            for filename in sorted(filenames):
                if filename in EXCLUDED_FILE_NAMES or filename.endswith(".swp"):
                    continue
                full_path = os.path.join(dirpath, filename)
                relative = os.path.relpath(full_path, root).replace(os.sep, "/")
                arcname = SLUG + "/" + relative
                archive.write(full_path, arcname)
                names.append(arcname)

    missing = [name for name in REQUIRED if name not in names]
    forbidden = [
        name
        for name in names
        if name.startswith(SLUG + "/tests/")
        or name.startswith(SLUG + "/bin/")
        or name.endswith("/bitbucket-pipelines.yml")
        or "/.git/" in "/" + name + "/"
    ]
    if missing or forbidden:
        print("zip is not a clean plugin build", file=sys.stderr)
        for name in missing:
            print("missing " + name, file=sys.stderr)
        for name in forbidden:
            print("forbidden " + name, file=sys.stderr)
        return 1

    print(output)
    return 0


if __name__ == "__main__":
    sys.exit(main())
