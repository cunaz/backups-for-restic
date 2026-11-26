#!/usr/bin/env python3
"""Check what GitHub enforces and what CI has shown before a release is deployed.

  check-release-gates.py protections --tag vX.Y.Z
      The rulesets of main and of the release tag, and the wordpress-org environment.
  check-release-gates.py evidence --commit SHA
      The workflow runs of the release commit on main.

Every requirement is reported with the reason it fails, and the exit status is 1 if one
fails. A requirement that cannot be read counts as failed. The release workflow runs both
commands with its own token (docs/04 §7); GITHUB_REPOSITORY, GITHUB_API_URL and GH_TOKEN or
GITHUB_TOKEN come from the runner. Two settings cannot be read with that token, the bypass
lists of the rulesets and WordPress.org's release confirmation, so the owner checks them by
hand (docs/04 §7.1).

The checks that the ruleset of main must require are listed in .github/required-checks.txt
(docs/04 §6), which the document and this script share.

--responses FILE answers each request from a JSON object that maps "path?query" to
{"status": ..., "body": ...}, for tests/Release/test_release_gates.py.
"""

import argparse
import fnmatch
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ENVIRONMENT = "wordpress-org"
LIVE_JOB = "Operator-configured private repositories"
ACCEPTANCE_SUFFIX = "(acceptance size)"
# ASCII digits only: \d and int() also take other scripts' digits, which bin/fuzz.php refuses.
FUZZ_JOB = re.compile(r"^Fuzz parsers \(([0-9]+) s per target\)$")
FUZZ_SECONDS = 120
MAIN = "main"
REQUIRED_CHECKS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".github", "required-checks.txt")


class Unreadable(Exception):
    """GitHub did not answer a request with data the check can use."""


class Api:
    def __init__(self, repository, responses=None):
        self.repository = repository
        self.responses = responses
        self.base = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")
        self.token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN") or ""

    def get(self, path, allow_missing=False, **query):
        """GET repos/{repository}/{path}; None for a 404 when allow_missing is set."""
        path = f"repos/{self.repository}/{path}"
        key = path + ("?" + urllib.parse.urlencode(sorted(query.items())) if query else "")
        if self.responses is not None:
            if key not in self.responses:
                raise Unreadable(f"no recorded response for {key}")
            status, body = self.responses[key]["status"], self.responses[key].get("body")
        else:
            status, body = self._request(key)
        if status == 404 and allow_missing:
            return None
        if status != 200:
            raise Unreadable(f"GitHub answered {key} with HTTP {status}")
        return body

    def _request(self, key):
        request = urllib.request.Request(
            f"{self.base}/{key}",
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": f"Bearer {self.token}",
                "X-GitHub-Api-Version": "2022-11-28",
            },
        )
        for attempt in range(3):
            try:
                with urllib.request.urlopen(request, timeout=30) as response:
                    return response.status, json.load(response)
            except urllib.error.HTTPError as error:
                if error.code < 500 or attempt == 2:
                    return error.code, None
            except (urllib.error.URLError, TimeoutError) as error:
                if attempt == 2:
                    raise Unreadable(f"GitHub did not answer {key}: {error}") from error
            time.sleep(2 ** attempt)
        raise Unreadable(f"GitHub did not answer {key}")


def escape(text, property_value=False):
    """Escape a value for a workflow command, as GitHub's toolkit does."""
    text = str(text).replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    return text.replace(":", "%3A").replace(",", "%2C") if property_value else text


class Report:
    def __init__(self):
        self.failures = 0

    def check(self, name, function, *args):
        try:
            problems, detail = function(*args)
        except Unreadable as error:
            problems, detail = [f"{error}; nothing can be verified without it"], ""
        except (KeyError, TypeError, AttributeError, ValueError) as error:
            problems, detail = [f"GitHub's answer has an unexpected form ({error!r}); nothing can be verified"], ""
        for problem in problems:
            self.failures += 1
            print(f"FAIL {name}: {problem}")
            if os.environ.get("GITHUB_ACTIONS") == "true":
                print(f"::error title={escape('Release gate: ' + name, True)}::{escape(problem)}")
        if not problems:
            print(f"ok   {name}: {detail}")


# Protections ------------------------------------------------------------------------------

def required_checks(path=REQUIRED_CHECKS):
    """The checks of .github/required-checks.txt: one per line, # starts a comment line."""
    try:
        with open(path, encoding="utf-8") as handle:
            names = [line.strip() for line in handle if line.strip() and not line.lstrip().startswith("#")]
    except OSError as error:
        raise Unreadable(f"the list of required checks cannot be read: {error}") from error
    if not names:
        raise Unreadable(f"{path} names no check")
    return names


def main_branch_rules(api):
    rules = api.get(f"rules/branches/{MAIN}")
    types = {rule.get("type") for rule in rules}
    checks = [
        r for r in rules if r.get("type") == "required_status_checks"
        and (r.get("parameters") or {}).get("required_status_checks")
    ]
    problems = []
    for rule, wording in (
        ("pull_request", "requires a pull request"),
        ("non_fast_forward", "blocks force pushes"),
        ("deletion", "blocks deletion"),
    ):
        if rule not in types:
            problems.append(f"no active ruleset {wording} on {MAIN}")
    required = required_checks()
    contexts = {c.get("context", "") for r in checks for c in r["parameters"]["required_status_checks"]}
    if not checks:
        problems.append(f"no active ruleset requires status checks on {MAIN}; it must require the {len(required)} checks of .github/required-checks.txt")
    else:
        problems.extend(
            f"no active ruleset requires the check '{name}' on {MAIN} (.github/required-checks.txt)"
            for name in required if name not in contexts
        )
    return problems, f"pull request, the {len(required)} checks of .github/required-checks.txt, no force push, no deletion"


def within_segments(name, pattern):
    """Whether name matches pattern as GitHub matches ruleset and deployment patterns, with
    File::FNM_PATHNAME: * and ? stay within one /-separated segment, [...] never matches /, and
    **/ at the start of a segment stands for any number of whole segments. Any other ** is read
    as *, so that a pattern counts only in the narrowest reading."""
    out, i = [], 0
    while i < len(pattern):
        if pattern.startswith("**/", i) and (i == 0 or pattern[i - 1] == "/"):
            out.append("(?:[^/]*/)*")
            i += 3
        elif pattern.startswith("**", i):
            out.append("[^/]*")
            i += 2
        elif pattern[i] == "*":
            out.append("[^/]*")
            i += 1
        elif pattern[i] == "?":
            out.append("[^/]")
            i += 1
        elif pattern[i] == "[" and (end := _bracket_end(pattern, i)) is not None:
            body = pattern[i + 1:end]
            negated = body.startswith("!")
            body = (body[1:] if negated else body).replace("\\", "\\\\").replace("[", "\\[")
            if not negated and body.startswith("^"):
                body = "\\" + body
            out.append("(?!/)[" + ("^" if negated else "") + body + "]")
            i = end + 1
        else:
            out.append(re.escape(pattern[i]))
            i += 1
    return re.fullmatch("".join(out), name) is not None


def _bracket_end(pattern, start):
    """The index of the ] that closes the class at start, as fnmatch reads it, or None."""
    j = start + 1
    if j < len(pattern) and pattern[j] == "!":
        j += 1
    if j < len(pattern) and pattern[j] == "]":
        j += 1
    end = pattern.find("]", j)
    return None if end < 0 else end


def applies(conditions, ref):
    """Whether a ruleset's ref_name conditions certainly cover ref, failing closed both ways: an
    include pattern counts only when it matches segment by segment (within_segments), and an
    exclude pattern removes the ruleset when it matches in either reading: segment by segment,
    where **/ may stand for no segment, or with * across /, as fnmatch reads it."""
    names = (conditions or {}).get("ref_name") or {}

    def special(pattern):
        if pattern == "~ALL":
            return True
        if pattern == "~DEFAULT_BRANCH":
            return ref == f"refs/heads/{MAIN}"
        return None

    def included(pattern):
        known = special(pattern)
        return within_segments(ref, pattern) if known is None else known

    def excluded(pattern):
        known = special(pattern)
        return fnmatch.fnmatchcase(ref, pattern) or within_segments(ref, pattern) if known is None else known

    return any(included(p) for p in names.get("include", [])) and not any(excluded(p) for p in names.get("exclude", []))


def tag_rules(api, tag):
    ref = f"refs/tags/{tag}"
    rulesets = api.get("rulesets", includes_parents="true", per_page="100", targets="tag")
    if len(rulesets) >= 100:
        raise Unreadable("more than 99 tag rulesets; the gate reads only the first page")
    types, names = set(), []
    for summary in rulesets:
        if summary.get("enforcement") != "active":
            continue
        ruleset = api.get(f"rulesets/{summary['id']}")
        if ruleset.get("target") == "tag" and ruleset.get("enforcement") == "active" and applies(ruleset.get("conditions"), ref):
            types.update(rule.get("type") for rule in ruleset.get("rules", []))
            names.append(ruleset.get("name", str(summary["id"])))
    problems = [
        f"no active tag ruleset stops {ref} from being {wording}"
        for rule, wording in (("update", "moved"), ("deletion", "deleted"))
        if rule not in types
    ]
    return problems, f"{ref} cannot be moved or deleted ({', '.join(names)})"


def environment(api, tag):
    env = api.get(f"environments/{ENVIRONMENT}", allow_missing=True)
    if env is None:
        return [f"the environment {ENVIRONMENT} does not exist; create it before the first tag, or GitHub creates it without protection"], ""
    owner = api.repository.split("/")[0]
    problems = []
    rules = [rule for rule in env.get("protection_rules", []) if rule.get("type") == "required_reviewers"]
    reviewers = [reviewer for rule in rules for reviewer in rule.get("reviewers", [])]

    def is_owner(entry):
        return entry.get("type") == "User" and (entry.get("reviewer") or {}).get("login") == owner

    def named(entry):
        reviewer = entry.get("reviewer") or {}
        if entry.get("type") == "User":
            return str(reviewer.get("login") or "a user")
        return f"the team {reviewer.get('slug') or reviewer.get('name') or '?'}"

    if not any(is_owner(r) for r in reviewers):
        problems.append(f"{ENVIRONMENT} does not require a review by {owner}")
    # GitHub needs the approval of one listed reviewer, so another one could approve alone.
    others = sorted({named(r) for r in reviewers if not is_owner(r)})
    if others:
        problems.append(f"{ENVIRONMENT} lets {', '.join(others)} approve a deployment instead of {owner}, who must be its only reviewer")
    # The owner pushes the release tag, so with self-review prevented nobody could approve.
    if any(rule.get("prevent_self_review") is not False for rule in rules):
        problems.append(f"{ENVIRONMENT} prevents self-review, or GitHub did not say that it does not: {owner} pushes the release tag and could not approve its deployment")
    # Not in GitHub's published schema, but returned by the API. A missing value fails.
    if env.get("can_admins_bypass") is not False:
        problems.append(f"{ENVIRONMENT} lets administrators bypass its protection rules, or GitHub did not say that it does not")
    policy = env.get("deployment_branch_policy") or {}
    if policy.get("protected_branches") is not False or policy.get("custom_branch_policies") is not True:
        problems.append(f"{ENVIRONMENT} does not limit deployments to selected tags")
        return problems, ""
    listed = api.get(f"environments/{ENVIRONMENT}/deployment-branch-policies", per_page="100").get("branch_policies", [])
    wrong = [p.get("name", "") for p in listed if p.get("type") != "tag" or not p.get("name", "").startswith("v")]
    if not listed or wrong:
        problems.append(f"{ENVIRONMENT} must allow only tags matching v* (it allows: {', '.join(p.get('type', '?') + ' ' + p.get('name', '?') for p in listed) or 'nothing'})")
    elif not any(within_segments(tag, p["name"]) for p in listed):
        problems.append(f"{ENVIRONMENT} does not allow the tag {tag}")
    return problems, f"review by {owner} alone, self-review allowed, no administrator bypass, tags {', '.join(p['name'] for p in listed)} only"


# Evidence ---------------------------------------------------------------------------------

def runs_of(api, workflow, commit, events):
    body = api.get(f"actions/workflows/{workflow}/runs", branch=MAIN, exclude_pull_requests="true", head_sha=commit, per_page="100")
    if body.get("total_count", 0) >= 100:
        raise Unreadable(f"{workflow} has 100 or more runs for {commit}; the gate reads only the first page")
    return [
        run for run in body.get("workflow_runs", [])
        if run.get("head_sha") == commit and run.get("head_branch") == MAIN and run.get("event") in events
        and (run.get("head_repository") or {}).get("full_name") == api.repository
    ]


def jobs_of(api, run):
    body = api.get(f"actions/runs/{run['id']}/jobs", filter="latest", per_page="100")
    if body.get("total_count", 0) >= 100:
        raise Unreadable(f"run {run['id']} has 100 or more jobs; the gate reads only the first page")
    return body.get("jobs", [])


def all_succeeded(runs, commit, hint):
    """Every run of the commit counts: a failure is not outweighed by a later success."""
    if not runs:
        return [f"no run for {commit[:12]} on {MAIN}; {hint}"]
    problems = []
    for run in runs:
        where = f"run {run.get('html_url', run['id'])} ({run.get('event')})"
        if run.get("status") != "completed":
            problems.append(f"{where} is {run.get('status') or 'in an unknown state'}; wait until it has finished")
        elif run.get("conclusion") != "success":
            problems.append(f"{where} ended in {run.get('conclusion') or 'an unknown state'}; re-run it if the failure was not caused by this commit")
    return problems


def workflow_passed(api, workflow, commit, events, hint):
    runs = runs_of(api, workflow, commit, events)
    return all_succeeded(runs, commit, hint), f"runs: {len(runs)}, all successful"


def acceptance_run(api, commit):
    events = {"push", "schedule", "workflow_dispatch"}
    runs = runs_of(api, "integration.yml", commit, events)
    problems = all_succeeded(runs, commit, "wait for the push run")
    large = [
        run for run in runs if run.get("event") != "push"
        and any(job.get("name", "").endswith(ACCEPTANCE_SUFFIX) for job in jobs_of(api, run))
    ]
    if not large:
        problems.append(f"no run of the acceptance size (2048 MB of files, 300 MB of database) for {commit[:12]}; dispatch Integration on {MAIN} with acceptance, or wait for the nightly run")
    return problems, f"runs: {len(runs)}, all successful, {len(large)} of the acceptance size"


def live_smoke(api, commit):
    runs = runs_of(api, "live-servers.yml", commit, {"schedule", "workflow_dispatch"})
    problems = all_succeeded(runs, commit, f"dispatch Private repository smoke on {MAIN}")
    hosted = [run for run in runs if any(job.get("name") == LIVE_JOB and job.get("conclusion") == "success" for job in jobs_of(api, run))]
    if runs and not hosted:
        problems.append(f"the job '{LIVE_JOB}' did not succeed in any run, it was skipped or failed; configure BFR_LIVE_SMOKE_ENABLED and the live-smoke environment (docs/04 §5.7)")
    return problems, f"runs: {len(runs)}, all successful, {len(hosted)} with the hosted repositories"


def fuzzing(api, commit):
    runs = runs_of(api, "fuzz.yml", commit, {"schedule", "workflow_dispatch"})
    # A run seeds the fuzzer with its run ID, so re-running a run that found a crash finds it again.
    problems = all_succeeded(runs, commit, f"dispatch Fuzzing on {MAIN} or wait for the nightly run")
    complete = []
    for run in runs:
        for job in jobs_of(api, run):
            match = FUZZ_JOB.match(job.get("name", ""))
            if match and int(match[1]) >= FUZZ_SECONDS and job.get("conclusion") == "success":
                complete.append(run)
    if runs and not complete:
        problems.append(f"no run fuzzed every target for at least {FUZZ_SECONDS} seconds")
    return problems, f"runs: {len(runs)}, none with a finding, {len(complete)} of at least {FUZZ_SECONDS} s per target"


def code_scanning(api, commit):
    problems, detail = workflow_passed(api, "codeql.yml", commit, {"push", "schedule", "workflow_dispatch"}, f"dispatch CodeQL on {MAIN}")
    alerts = api.get("code-scanning/alerts", per_page="100", ref=f"refs/heads/{MAIN}", state="open")
    if alerts:
        problems.append(f"{len(alerts)} open code scanning alerts on {MAIN}")
    return problems, f"{detail}; no open alert"


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("group", choices=("protections", "evidence"))
    parser.add_argument("--tag")
    parser.add_argument("--commit")
    parser.add_argument("--repository", default=os.environ.get("GITHUB_REPOSITORY"))
    parser.add_argument("--responses", help="recorded responses instead of the GitHub API (tests)")
    args = parser.parse_args()
    if not args.repository or not re.fullmatch(r"[\w.-]+/[\w.-]+", args.repository):
        parser.error("--repository OWNER/NAME or GITHUB_REPOSITORY is required")
    if args.group == "protections" and not re.fullmatch(r"v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", args.tag or ""):
        parser.error("protections needs --tag vX.Y.Z")
    if args.group == "evidence" and not re.fullmatch(r"[0-9a-f]{40}", args.commit or ""):
        parser.error("evidence needs --commit with a full commit SHA")
    responses = None
    if args.responses:
        with open(args.responses, encoding="utf-8") as handle:
            responses = json.load(handle)
    api = Api(args.repository, responses)
    report = Report()
    if args.group == "protections":
        report.check(f"ruleset of {MAIN}", main_branch_rules, api)
        report.check("ruleset of release tags", tag_rules, api, args.tag)
        report.check(f"environment {ENVIRONMENT}", environment, api, args.tag)
    else:
        commit = args.commit
        report.check("CI", workflow_passed, api, "ci.yml", commit, {"push"}, "wait for the push run")
        report.check("Conformance", workflow_passed, api, "conformance.yml", commit, {"push", "schedule", "workflow_dispatch"}, f"dispatch Conformance on {MAIN}")
        report.check("Integration, acceptance size", acceptance_run, api, commit)
        report.check("Database mutation testing", workflow_passed, api, "database-mutation.yml", commit, {"push", "workflow_dispatch"}, f"dispatch Database mutation testing on {MAIN}")
        report.check("Private repository smoke", live_smoke, api, commit)
        report.check("Fuzzing", fuzzing, api, commit)
        report.check("CodeQL", code_scanning, api, commit)
    if report.failures:
        print(f"{report.failures} release requirements are not met.")
        sys.exit(1)
    print("All release requirements are met.")


if __name__ == "__main__":
    main()
