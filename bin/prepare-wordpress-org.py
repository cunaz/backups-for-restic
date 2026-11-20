#!/usr/bin/env python3
"""Prepare and verify one WordPress.org release in a new local SVN working copy."""

import argparse
import filecmp
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET


VERSION = re.compile(r"(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){2}")


def svn(*args):
    return subprocess.check_output(["svn", "--non-interactive", "--no-auth-cache", *args], text=True)


def require_version(package, version):
    for name, pattern in (
        ("backups-for-restic.php", r"^ \* Version:\s*(\S+)\s*$"),
        ("readme.txt", r"^Stable tag:\s*(\S+)\s*$"),
        ("src/Plugin.php", r"^\s*public const string VERSION = '([^']+)';$"),
    ):
        match = re.search(pattern, (package / name).read_text(), re.MULTILINE)
        if match is None or match[1] != version:
            raise SystemExit(f"{name} must identify release {version}")


def entries(root):
    names = set()
    for path in root.rglob("*"):
        if path.is_symlink() or not (path.is_file() or path.is_dir()):
            raise SystemExit(f"Unexpected file type at {path.relative_to(root)}")
        names.add(path.relative_to(root))
    return names


def compare(expected, actual):
    if entries(expected) != entries(actual):
        raise SystemExit(f"Release paths differ from the expected tree in {expected.name}")
    for relative in entries(expected):
        left, right = expected / relative, actual / relative
        if left.is_dir() != right.is_dir() or (left.is_file() and not filecmp.cmp(left, right, shallow=False)):
            raise SystemExit(f"Release content differs for {relative}")


def sync(source, destination):
    entries(source)
    subprocess.run(["rsync", "-r", "--checksum", "--delete", "--exclude=.svn", f"{source}/", f"{destination}/"], check=True)
    # Schedule removals before additions. An absent directory covers all its former children.
    status = ET.fromstring(svn("status", "--xml", destination))
    missing = sorted(
        (Path(entry.attrib["path"]) for entry in status.findall(".//entry")
         if entry.find("wc-status").attrib["item"] == "missing"),
        key=lambda path: len(path.parts),
    )
    removed = set()
    for path in missing:
        if removed.isdisjoint(path.parents):
            svn("delete", "--force", path)
            removed.add(path)
    svn("add", "--force", "--no-ignore", destination)


def verify_export(source, url, directory):
    svn("export", "--ignore-externals", url, directory)
    compare(source, directory)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version")
    parser.add_argument("package", type=Path)
    parser.add_argument("assets", type=Path)
    parser.add_argument("repository", help="SVN URL of the plugin or a local file:// rehearsal repository")
    parser.add_argument("checkout", type=Path, help="Working copy to create")
    args = parser.parse_args()
    if not VERSION.fullmatch(args.version):
        parser.error("a stable x.y.z version is required")
    if not args.repository.startswith(("https://", "file://")):
        parser.error("an HTTPS or file repository URL is required")
    if args.checkout.exists() or not args.assets.is_dir():
        parser.error("use a new checkout and an existing listing asset directory")

    require_version(args.package, args.version)
    args.checkout = args.checkout.absolute()
    svn("checkout", "--depth", "immediates", "--ignore-externals", args.repository, args.checkout)
    trunk, tags, assets = (args.checkout / part for part in ("trunk", "tags", "assets"))
    if not all(path.is_dir() for path in (trunk, tags, assets)):
        raise SystemExit("The WordPress.org repository requires trunk/, tags/ and assets/")
    svn("update", "--ignore-externals", "--set-depth", "immediates", tags)
    tag = tags / args.version
    with tempfile.TemporaryDirectory() as tmp:
        check = Path(tmp)
        if tag.exists():
            # Lost response after commit or a rerun: verify the immutable tag without changing trunk.
            verify_export(args.package, args.repository.rstrip("/") + "/tags/" + args.version, check / "tag")
            print(f"SVN tag {args.version} already contains this exact package")
            return
        version = tuple(map(int, args.version.split(".")))
        if any(VERSION.fullmatch(p.name) and tuple(map(int, p.name.split("."))) >= version for p in tags.iterdir()):
            raise SystemExit("A newer release already exists in SVN")

        svn("update", "--ignore-externals", "--set-depth", "infinity", trunk, assets)
        sync(args.package, trunk)
        sync(args.assets, assets)
        for path in assets.iterdir():
            mime = {".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg"}.get(path.suffix.lower())
            if mime:
                svn("propset", "svn:mime-type", mime, path)
        svn("copy", trunk, tag)
        verify_export(args.package, tag, check / "tag")
        verify_export(args.assets, assets, check / "assets")
        print(f"Prepared and verified SVN trunk, assets and tag {args.version}")


if __name__ == "__main__":
    main()
