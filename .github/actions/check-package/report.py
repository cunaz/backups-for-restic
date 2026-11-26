#!/usr/bin/env python3
"""Report the result of `wp plugin check --format=strict-json` and fail on an error.

Plugin Check prints "Success: ..." when it finds nothing, and otherwise one JSON array of
findings, each with file, line, column, type (ERROR or WARNING), code and message. Errors fail;
warnings become annotations. Any other output fails too: a check that did not run must not pass.
"""

import json
import sys


def escape(text, property_value=False):
    """Escape a value for a workflow command, as GitHub's toolkit does."""
    text = str(text).replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    return text.replace(":", "%3A").replace(",", "%2C") if property_value else text


def main():
    with open(sys.argv[1], encoding="utf-8") as handle:
        lines = [line.strip() for line in handle if line.strip()]
    if len(lines) == 1 and lines[0].startswith("Success:"):
        print(lines[0])
        return 0
    findings = None
    if len(lines) == 1 and lines[0].startswith("["):
        try:
            findings = json.loads(lines[0])
        except ValueError:
            findings = None
    if not isinstance(findings, list) or not all(isinstance(f, dict) and f.get("type") in ("ERROR", "WARNING") for f in findings):
        print("::error::Plugin Check printed something other than its results; see the log.")
        print("\n".join(lines[:50]))
        return 1
    errors = 0
    for finding in findings:
        level = "error" if finding["type"] == "ERROR" else "warning"
        errors += finding["type"] == "ERROR"
        location = ",".join(f"{key}={escape(finding.get(field, ''), True)}" for key, field in (("file", "file"), ("line", "line"), ("col", "column")))
        print(f"::{level} {location}::{escape(finding.get('code', ''))}: {escape(finding.get('message', ''))}")
    print(f"Plugin Check: {errors} errors, {len(findings) - errors} warnings after the ignored codes.")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
