#!/usr/bin/env python3
"""The release gates of bin/check-release-gates.py against recorded GitHub API responses.

Each case starts from a repository in which every requirement is met and changes one answer,
then checks that the gate fails for that reason and names it. Run: python3 tests/Release/test_release_gates.py
"""

import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest
import urllib.parse

SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "bin", "check-release-gates.py")
REPO = "example/plugin"
OWNER = "example"
TAG = "v1.2.3"
COMMIT = "0123456789abcdef0123456789abcdef01234567"


def key(path, **query):
    path = f"repos/{REPO}/{path}"
    return path + ("?" + urllib.parse.urlencode(sorted(query.items())) if query else "")


def ok(body):
    return {"status": 200, "body": body}


def runs_key(workflow):
    return key(f"actions/workflows/{workflow}/runs", branch="main", exclude_pull_requests="true", head_sha=COMMIT, per_page="100")


def jobs_key(run_id):
    return key(f"actions/runs/{run_id}/jobs", filter="latest", per_page="100")


def run(run_id, event, conclusion="success", status="completed", sha=COMMIT, branch="main", repository=REPO):
    return {
        "id": run_id, "event": event, "status": status, "conclusion": conclusion, "head_sha": sha,
        "head_branch": branch, "head_repository": {"full_name": repository},
        "html_url": f"https://github.com/{REPO}/actions/runs/{run_id}",
    }


def runs(*items):
    return ok({"total_count": len(items), "workflow_runs": list(items)})


def jobs(*names, conclusion="success"):
    return ok({"total_count": len(names), "jobs": [{"name": name, "status": "completed", "conclusion": conclusion} for name in names]})


def protections():
    return {
        key("rules/branches/main"): ok([
            {"type": "deletion", "ruleset_id": 1},
            {"type": "non_fast_forward", "ruleset_id": 1},
            {"type": "pull_request", "ruleset_id": 1, "parameters": {"required_approving_review_count": 0}},
            {"type": "required_status_checks", "ruleset_id": 1, "parameters": {"required_status_checks": [{"context": "Unit tests (PHP 8.3)"}]}},
        ]),
        key("rulesets", includes_parents="true", per_page="100", targets="tag"): ok([{"id": 2, "target": "tag", "enforcement": "active"}]),
        key("rulesets/2"): ok({
            "id": 2, "name": "Release tags", "target": "tag", "enforcement": "active",
            "conditions": {"ref_name": {"include": ["refs/tags/v*"], "exclude": []}},
            "rules": [{"type": "update"}, {"type": "deletion"}],
        }),
        key("environments/wordpress-org"): ok({
            "name": "wordpress-org", "can_admins_bypass": False,
            "protection_rules": [
                {"type": "required_reviewers", "prevent_self_review": False, "reviewers": [{"type": "User", "reviewer": {"login": OWNER}}]},
                {"type": "branch_policy"},
            ],
            "deployment_branch_policy": {"protected_branches": False, "custom_branch_policies": True},
        }),
        key("environments/wordpress-org/deployment-branch-policies", per_page="100"): ok({"total_count": 1, "branch_policies": [{"id": 3, "name": "v*", "type": "tag"}]}),
    }


def evidence():
    return {
        runs_key("ci.yml"): runs(run(10, "push")),
        runs_key("conformance.yml"): runs(run(11, "push"), run(12, "schedule")),
        runs_key("integration.yml"): runs(run(13, "push"), run(14, "schedule")),
        jobs_key(14): jobs("WordPress 7.1.5 on PHP 8.3 (acceptance size)", "WordPress 7.1.5 on PHP 8.5", "Admin page in a browser"),
        runs_key("database-mutation.yml"): runs(run(15, "push")),
        runs_key("live-servers.yml"): runs(run(16, "workflow_dispatch")),
        jobs_key(16): jobs("Disposable private TLS rest-server", "Operator-configured private repositories"),
        runs_key("fuzz.yml"): runs(run(17, "schedule")),
        jobs_key(17): jobs("Fuzz parsers (120 s per target)"),
        runs_key("codeql.yml"): runs(run(18, "workflow_dispatch")),
        key("code-scanning/alerts", per_page="100", ref="refs/heads/main", state="open"): ok([]),
    }


class ReleaseGates(unittest.TestCase):
    def gate(self, responses, *arguments):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as handle:
            json.dump(responses, handle)
        try:
            result = subprocess.run(
                [sys.executable, "-I", SCRIPT, *arguments, "--repository", REPO, "--responses", handle.name],
                capture_output=True, text=True, env={"PATH": os.environ.get("PATH", "")}, check=False,
            )
        finally:
            os.unlink(handle.name)
        self.assertEqual("", result.stderr)
        return result.returncode, result.stdout

    def protections(self, responses):
        return self.gate(responses, "protections", "--tag", TAG)

    def evidence(self, responses):
        return self.gate(responses, "evidence", "--commit", COMMIT)

    def refused(self, outcome, *reasons):
        status, output = outcome
        self.assertEqual(1, status, output)
        for reason in reasons:
            self.assertIn(reason, output)

    def test_a_protected_repository_passes(self):
        status, output = self.protections(protections())
        self.assertEqual(0, status, output)
        self.assertIn("All release requirements are met.", output)

    def test_a_repository_without_protection_fails_for_every_missing_rule(self):
        responses = protections()
        responses[key("rules/branches/main")] = ok([])
        responses[key("rulesets", includes_parents="true", per_page="100", targets="tag")] = ok([])
        responses[key("environments/wordpress-org")] = {"status": 404, "body": {"message": "Not Found"}}
        self.refused(
            self.protections(responses),
            "no active ruleset requires a pull request on main",
            "no active ruleset requires status checks on main",
            "no active ruleset blocks force pushes on main",
            "no active ruleset blocks deletion on main",
            "no active tag ruleset stops refs/tags/v1.2.3 from being moved",
            "no active tag ruleset stops refs/tags/v1.2.3 from being deleted",
            "the environment wordpress-org does not exist",
            "7 release requirements are not met.",
        )

    def test_status_checks_must_name_at_least_one_check(self):
        responses = protections()
        rules = responses[key("rules/branches/main")]["body"]
        rules[3]["parameters"]["required_status_checks"] = []
        self.refused(self.protections(responses), "no active ruleset requires status checks on main")

    def test_a_tag_ruleset_in_evaluate_mode_or_with_an_exclusion_does_not_count(self):
        responses = protections()
        listing = key("rulesets", includes_parents="true", per_page="100", targets="tag")
        responses[listing]["body"][0]["enforcement"] = "evaluate"
        self.refused(self.protections(responses), "no active tag ruleset stops refs/tags/v1.2.3 from being moved")
        responses = protections()
        responses[key("rulesets/2")]["body"]["conditions"]["ref_name"]["exclude"] = ["refs/tags/v1.*"]
        self.refused(self.protections(responses), "no active tag ruleset stops refs/tags/v1.2.3 from being deleted")

    def test_a_tag_ruleset_must_block_both_moving_and_deleting(self):
        responses = protections()
        responses[key("rulesets/2")]["body"]["rules"] = [{"type": "deletion"}, {"type": "creation"}]
        status, output = self.protections(responses)
        self.refused((status, output), "stops refs/tags/v1.2.3 from being moved")
        self.assertNotIn("from being deleted", output)

    def test_the_environment_needs_the_owner_as_reviewer(self):
        responses = protections()
        rules = responses[key("environments/wordpress-org")]["body"]["protection_rules"]
        rules[0]["reviewers"] = [{"type": "User", "reviewer": {"login": "someone-else"}}]
        self.refused(self.protections(responses), "wordpress-org does not require a review by example")
        responses = protections()
        responses[key("environments/wordpress-org")]["body"]["protection_rules"] = [{"type": "branch_policy"}]
        self.refused(self.protections(responses), "wordpress-org does not require a review by example")

    def test_administrators_must_not_bypass_the_environment(self):
        for value in (True, None):
            responses = protections()
            environment = responses[key("environments/wordpress-org")]["body"]
            if value is None:
                del environment["can_admins_bypass"]
            else:
                environment["can_admins_bypass"] = value
            self.refused(self.protections(responses), "wordpress-org lets administrators bypass its protection rules")

    def test_deployments_must_be_limited_to_release_tags(self):
        responses = protections()
        responses[key("environments/wordpress-org")]["body"]["deployment_branch_policy"] = None
        self.refused(self.protections(responses), "wordpress-org does not limit deployments to selected tags")
        responses = protections()
        policies = key("environments/wordpress-org/deployment-branch-policies", per_page="100")
        responses[policies]["body"]["branch_policies"].append({"id": 4, "name": "main", "type": "branch"})
        self.refused(self.protections(responses), "wordpress-org must allow only tags matching v* (it allows: tag v*, branch main)")
        responses[policies]["body"]["branch_policies"] = [{"id": 5, "name": "v2.*", "type": "tag"}]
        self.refused(self.protections(responses), "wordpress-org does not allow the tag v1.2.3")

    def test_an_unreadable_answer_fails_closed(self):
        responses = protections()
        responses[key("environments/wordpress-org")] = {"status": 403, "body": {"message": "Resource not accessible by integration"}}
        self.refused(self.protections(responses), "environments/wordpress-org with HTTP 403; nothing can be verified")
        responses = protections()
        responses[key("rules/branches/main")] = ok({"unexpected": "form"})
        self.refused(self.protections(responses), "unexpected form")
        responses = protections()
        del responses[key("rulesets/2")]
        self.refused(self.protections(responses), "no recorded response for repos/example/plugin/rulesets/2")

    def test_complete_evidence_passes(self):
        status, output = self.evidence(evidence())
        self.assertEqual(0, status, output)
        self.assertIn("All release requirements are met.", output)

    def test_every_run_of_the_commit_counts_and_a_later_success_does_not_hide_a_failure(self):
        responses = evidence()
        responses[runs_key("integration.yml")] = runs(run(13, "push"), run(19, "schedule", conclusion="failure"), run(14, "schedule"))
        responses[jobs_key(19)] = jobs("WordPress 7.1.5 on PHP 8.3 (acceptance size)", conclusion="failure")
        self.refused(self.evidence(responses), "actions/runs/19 (schedule) ended in failure; re-run it")
        responses = evidence()
        responses[runs_key("conformance.yml")] = runs(run(11, "push"), run(20, "workflow_dispatch", status="in_progress", conclusion=None))
        self.refused(self.evidence(responses), "actions/runs/20 (workflow_dispatch) is in_progress; wait until it has finished")

    def test_integration_needs_a_run_of_the_acceptance_size(self):
        responses = evidence()
        responses[runs_key("integration.yml")] = runs(run(13, "push"), run(21, "workflow_dispatch"))
        responses[jobs_key(21)] = jobs("WordPress 7.1.5 on PHP 8.3", "WordPress 7.1.5 on PHP 8.5")
        self.refused(self.evidence(responses), "Integration, acceptance size: no run of the acceptance size (2048 MB of files, 300 MB of database)")

    def test_runs_of_other_commits_branches_events_or_repositories_are_not_evidence(self):
        responses = evidence()
        responses[runs_key("ci.yml")] = runs(
            run(30, "push", sha="f" * 40),
            run(31, "push", branch="feature"),
            run(32, "pull_request"),
            run(33, "push", repository="someone/fork"),
        )
        self.refused(self.evidence(responses), "CI: no run for 0123456789ab on main")

    def test_the_live_smoke_needs_the_hosted_job(self):
        responses = evidence()
        responses[jobs_key(16)] = ok({"total_count": 2, "jobs": [
            {"name": "Disposable private TLS rest-server", "status": "completed", "conclusion": "success"},
            {"name": "Operator-configured private repositories", "status": "completed", "conclusion": "skipped"},
        ]})
        self.refused(self.evidence(responses), "the job 'Operator-configured private repositories' did not succeed in any run")
        responses = evidence()
        responses[runs_key("live-servers.yml")] = runs()
        self.refused(self.evidence(responses), "Private repository smoke: no run for 0123456789ab on main")

    def test_fuzzing_needs_the_nightly_duration_and_no_finding(self):
        responses = evidence()
        responses[jobs_key(17)] = jobs("Fuzz parsers (10 s per target)")
        self.refused(self.evidence(responses), "no run fuzzed every target for at least 120 seconds")
        # Arabic-Indic digits read as 120 with \d and int(); bin/fuzz.php refuses them.
        responses[jobs_key(17)] = jobs("Fuzz parsers (\u0661\u0662\u0660 s per target)")
        self.refused(self.evidence(responses), "no run fuzzed every target for at least 120 seconds")
        responses = evidence()
        responses[runs_key("fuzz.yml")] = runs(run(17, "schedule"), run(22, "workflow_dispatch", conclusion="failure"))
        responses[jobs_key(22)] = jobs("Fuzz parsers (120 s per target)", conclusion="failure")
        self.refused(self.evidence(responses), "actions/runs/22 (workflow_dispatch) ended in failure")

    def test_code_scanning_needs_a_run_and_no_open_alert(self):
        responses = evidence()
        responses[key("code-scanning/alerts", per_page="100", ref="refs/heads/main", state="open")] = ok([{"number": 1}])
        self.refused(self.evidence(responses), "1 open code scanning alerts on main")
        responses = evidence()
        responses[runs_key("codeql.yml")] = runs()
        self.refused(self.evidence(responses), "CodeQL: no run for 0123456789ab on main; dispatch CodeQL on main")

    def test_database_mutation_testing_is_required(self):
        responses = evidence()
        responses[runs_key("database-mutation.yml")] = runs(run(15, "push", conclusion="cancelled"))
        self.refused(self.evidence(responses), "Database mutation testing: run https://github.com/example/plugin/actions/runs/15 (push) ended in cancelled")

    def test_a_release_candidate_tag_or_a_short_commit_is_refused(self):
        for arguments, reason in (
            (["protections", "--tag", "v1.2.3-rc.1"], "protections needs --tag vX.Y.Z"),
            (["evidence", "--commit", COMMIT[:12]], "evidence needs --commit with a full commit SHA"),
        ):
            result = subprocess.run(
                [sys.executable, "-I", SCRIPT, *arguments, "--repository", REPO, "--responses", os.devnull],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(2, result.returncode)
            self.assertIn(reason, result.stderr)


if __name__ == "__main__":
    unittest.main()
