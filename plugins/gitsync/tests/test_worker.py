"""Unit and real HTTP tests. Python >=3.12; no NAS, Docker or external Git server."""
import concurrent.futures
import http.client
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).parents[1] / "worker"))
from core import Engine, Journal, Problem, Store, command, environment, synchronize
from server import Server

REV = "a" * 40
NEW = "b" * 40
TOKEN = "a" * 64


class Fixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.now = 1800000000.0
        self.store = Store(self.base / "data/jobs.db", self.base / "repos", clock=lambda: self.now)

    def tearDown(self):
        self.doCleanups()
        self.temp.cleanup()

    def config(self, **kwargs):
        result = dict(name="Plugins GLPI", url="https://github.com/example/plugin.git",
                      branch="main", path="project", script="", auto_pull=False, interval_minutes=15)
        result.update(kwargs)
        return result

    def error(self, status, function):
        with self.assertRaises(Problem) as caught:
            function()
        self.assertEqual(caught.exception.status, status)


class StoreTests(Fixture):
    def test_create_update_and_persist(self):
        first = self.store.save(self.config())
        self.assertEqual(first["host_path"], "/mnt/Apps/GLPI/repos/project")
        values = self.config(id=first["id"], name="Autre nom", auto_pull=True)
        self.store.save(values)
        reopened = Store(self.base / "data/jobs.db", self.base / "repos", clock=lambda: self.now)
        job = reopened.get(first["id"])
        self.assertEqual(job["name"], "Autre nom")
        self.assertTrue(job["auto_pull"])
        self.assertEqual(job["next_run"], self.now + 900)

    def test_no_fixed_repository_count_and_pagination(self):
        for index in range(29):
            self.store.save(self.config(path="repo-" + str(index)))
        self.assertEqual(self.store.list()["total"], 29)
        self.assertEqual(len(self.store.list(1)["jobs"]), 12)
        self.assertEqual(len(self.store.list(3)["jobs"]), 5)
        self.assertEqual(self.store.list(999)["page"], 3)

    def test_host_absolute_path_and_no_overlap(self):
        self.store.save(self.config(path="/mnt/Apps/GLPI/repos/group/project"))
        for path in ["group", "group/project", "group/project/nested"]:
            self.error(409, lambda: self.store.save(self.config(path=path)))
        self.store.save(self.config(path="other/project"))

    def test_path_traversal_absolute_and_hidden_paths_refused(self):
        for path in ["../escape", "repo/../escape", "/etc", "/mnt/Apps/GLPI/repos", ".",
                     "repo//nested", "repo/.git", "repo/./nested", "repo\\escape"]:
            self.error(400, lambda: self.store.save(self.config(path=path)))

    @unittest.skipIf(os.name == "nt", "Windows symlinks may require developer privileges")
    def test_symlink_destination_refused(self):
        (self.base / "repos/project").symlink_to(self.base, target_is_directory=True)
        self.error(400, lambda: self.store.save(self.config()))

    def test_unsafe_urls_refused(self):
        for url in ["file:///tmp/repo", "ssh://git@host/repo", "https://user:secret@host/repo",
                    "https://host:8080/repo", "https://host/repo?token=secret",
                    "https://host/repo#x", "https://host/repo\n", "ext::cmd", "http://host/repo"]:
            if url.endswith("\n"):
                url = "https://host/re\npo"
            self.error(400, lambda: self.store.save(self.config(url=url)))

    def test_branch_and_script_restrictions(self):
        for branch in ["--upload-pack=evil", "../bad", "main..evil", "main//bad", ".hidden", "x/.bad", "bad.lock"]:
            self.error(400, lambda: self.store.save(self.config(branch=branch)))
        for script in ["/bin/sh", "../escape.sh", "scripts/../../escape", "deploy.sh;curl evil", ".git/hooks/x"]:
            self.error(400, lambda: self.store.save(self.config(script=script)))
        self.store.save(self.config(script="scripts/deploy.sh"))

    def test_type_and_interval_validation(self):
        for values in [dict(auto_pull="true"), dict(interval_minutes=0), dict(interval_minutes=True),
                       dict(interval_minutes=10081), dict(id=-1), dict(arbitrary="command")]:
            self.error(400, lambda: self.store.save(self.config(**values)))

    def test_duplicate_launch_and_busy_edit_delete_refused(self):
        job = self.store.save(self.config())
        self.store.enqueue(job["id"])
        self.error(409, lambda: self.store.enqueue(job["id"]))
        self.error(409, lambda: self.store.save(self.config(id=job["id"])))
        self.error(409, lambda: self.store.delete(job["id"]))
        self.assertEqual(self.store.claim()["status"], "running")
        self.assertIsNone(self.store.claim())

    def test_concurrent_enqueue_one_winner(self):
        job = self.store.save(self.config())
        def enqueue(_):
            try:
                self.store.enqueue(job["id"])
                return True
            except Problem:
                return False
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            self.assertEqual(sum(pool.map(enqueue, range(8))), 1)

    def test_delete_preserves_clone_and_never_reuses_id(self):
        job = self.store.save(self.config())
        destination = self.store.destination("project")
        destination.mkdir()
        (destination / "keep.txt").write_text("keep")
        self.store.delete(job["id"])
        self.assertEqual((destination / "keep.txt").read_text(), "keep")
        next_job = self.store.save(self.config())
        self.assertGreater(next_job["id"], job["id"])

    def test_restart_does_not_replay_uncertain_script(self):
        job = self.store.save(self.config(auto_pull=True))
        self.store.enqueue(job["id"])
        self.store.claim()
        reopened = Store(self.base / "data/jobs.db", self.base / "repos", clock=lambda: self.now)
        self.assertEqual(reopened.get(job["id"])["status"], "error")
        self.assertIsNone(reopened.claim())
        self.assertGreater(reopened.get(job["id"])["next_run"], self.now)


class SchedulerTests(Fixture):
    def engine(self, runner=None):
        return Engine(self.store, self.base / "logs",
                      runner or (lambda store, job, log: ("OK", REV)))

    def test_automatic_runs_without_any_browser_request(self):
        job = self.store.save(self.config(auto_pull=True, interval_minutes=5))
        engine = self.engine()
        self.assertFalse(engine.step())
        self.now += 301
        self.assertTrue(engine.step())
        value = self.store.get(job["id"])
        self.assertEqual(value["status"], "success")
        self.assertTrue(value["last_attempt"])
        self.assertEqual(value["last_commit"], REV)
        self.assertEqual(value["next_run"], self.now + 300)

    def test_disabled_never_automatically_queued(self):
        self.store.save(self.config())
        self.now += 999999
        self.assertFalse(self.engine().step())

    def test_failure_visible_and_next_retry_scheduled(self):
        job = self.store.save(self.config(auto_pull=True))
        def fail(*_):
            raise Problem(422, "Script en échec.")
        self.store.enqueue(job["id"])
        self.engine(fail).step()
        value = self.store.get(job["id"])
        self.assertEqual(value["status"], "error")
        self.assertEqual(value["message"], "Script en échec.")
        self.assertGreater(value["next_run"], self.now)

    def test_disabling_automatic_clears_next_run(self):
        job = self.store.save(self.config(auto_pull=True))
        self.store.save(self.config(id=job["id"], auto_pull=False))
        self.now += 1000
        self.assertFalse(self.engine().step())

    def test_serial_execution(self):
        order = []
        for index in range(3):
            job = self.store.save(self.config(path="repo" + str(index)))
            self.store.enqueue(job["id"])
        def runner(store, job, log):
            order.append(job["id"])
            return "OK", REV
        engine = self.engine(runner)
        for _ in range(3):
            self.assertTrue(engine.step())
        self.assertEqual(order, sorted(order))
        self.assertFalse(engine.step())


class FakeGit:
    def __init__(self, destination, before=REV, after=REV, dirty="", remote=None):
        self.destination = destination
        self.before = before
        self.after = after
        self.dirty = dirty
        self.remote = remote or "https://github.com/example/plugin.git"
        self.calls = []
        self.fetched = False

    def __call__(self, args, cwd, env, journal, **options):
        self.calls.append((args, options))
        if args[0] == "/bin/sh":
            return ""
        if "clone" in args:
            self.destination.mkdir(exist_ok=True)
            (self.destination / ".git").mkdir(exist_ok=True)
            return ""
        if args[-2:] == ["rev-parse", "--show-toplevel"]:
            return str(self.destination.resolve())
        if args[-3:] == ["remote", "get-url", "origin"]:
            return self.remote
        if args[-3:] == ["symbolic-ref", "--short", "HEAD"]:
            return "main"
        if args[-2:] == ["status", "--porcelain"]:
            return self.dirty
        if "fetch" in args:
            self.fetched = True
        if args[-2:] == ["rev-parse", "HEAD"]:
            return self.after if self.fetched or self.before == "" else self.before
        return ""


class GitTests(Fixture):
    def prepare(self, mode="auto", previous=REV, script="deploy.sh", existing=True):
        saved = self.store.save(self.config(script=script))
        job = self.store.get(saved["id"])
        job.update(mode=mode, last_commit=previous)
        destination = self.store.destination(job["path"])
        if existing:
            destination.mkdir()
            (destination / ".git").mkdir()
            if script:
                (destination / script).write_text("echo ok")
        journal = Journal(self.base / "journal.log")
        self.addCleanup(journal.close)
        return job, destination, journal

    def test_automatic_no_change_skips_script(self):
        job, destination, journal = self.prepare()
        fake = FakeGit(destination)
        message, commit = synchronize(self.store, job, journal, fake)
        self.assertEqual(commit, REV)
        self.assertIn("déjà à jour", message)
        self.assertFalse(any(args[0] == "/bin/sh" for args, _ in fake.calls))

    def test_first_clone_uses_requested_branch_and_destination(self):
        job, destination, journal = self.prepare(existing=False, script="")
        fake = FakeGit(destination)
        synchronize(self.store, job, journal, fake)
        args = fake.calls[0][0]
        self.assertIn("clone", args)
        self.assertEqual(args[-3:], ["--", job["url"], str(destination)])
        self.assertIn("--single-branch", args)
        self.assertTrue((destination / ".git").exists())

    def test_manual_no_change_executes_script(self):
        job, destination, journal = self.prepare(mode="manual")
        fake = FakeGit(destination)
        synchronize(self.store, job, journal, fake)
        scripts = [args for args, _ in fake.calls if args[0] == "/bin/sh"]
        self.assertEqual(scripts, [["/bin/sh", str((destination / "deploy.sh").resolve())]])

    def test_changed_commit_executes_script(self):
        job, destination, journal = self.prepare()
        fake = FakeGit(destination, after=NEW)
        _, commit = synchronize(self.store, job, journal, fake)
        self.assertEqual(commit, NEW)
        self.assertTrue(any(args[0] == "/bin/sh" for args, _ in fake.calls))

    def test_failed_deploy_same_commit_retries_script(self):
        job, destination, journal = self.prepare(previous="0" * 40)
        fake = FakeGit(destination)
        synchronize(self.store, job, journal, fake)
        self.assertTrue(any(args[0] == "/bin/sh" for args, _ in fake.calls))

    def test_optional_empty_script(self):
        job, destination, journal = self.prepare(mode="manual", script="")
        fake = FakeGit(destination)
        synchronize(self.store, job, journal, fake)
        self.assertFalse(any(args[0] == "/bin/sh" for args, _ in fake.calls))

    def test_existing_non_git_directory_never_removed(self):
        job, destination, journal = self.prepare(existing=False)
        destination.mkdir()
        (destination / "keep").write_text("keep")
        self.error(422, lambda: synchronize(self.store, job, journal, FakeGit(destination)))
        self.assertTrue((destination / "keep").exists())

    def test_dirty_clone_refused_before_fetch(self):
        job, destination, journal = self.prepare()
        fake = FakeGit(destination, dirty=" M local.php")
        self.error(422, lambda: synchronize(self.store, job, journal, fake))
        self.assertFalse(fake.fetched)

    def test_different_remote_refused(self):
        job, destination, journal = self.prepare()
        self.error(422, lambda: synchronize(self.store, job, journal, FakeGit(destination, remote="https://other/repo.git")))

    def test_missing_script_fails(self):
        job, destination, journal = self.prepare(script="")
        job["script"] = "missing.sh"
        job["mode"] = "manual"
        self.error(422, lambda: synchronize(self.store, job, journal, FakeGit(destination)))

    def test_git_uses_no_shell_protocols_hooks_or_forced_reset(self):
        job, destination, journal = self.prepare(script="")
        fake = FakeGit(destination)
        synchronize(self.store, job, journal, fake)
        for args, _ in fake.calls:
            self.assertIn("core.hooksPath=/dev/null", args)
            self.assertIn("http.followRedirects=false", args)
            self.assertNotIn("--hard", args)
        self.assertEqual(environment(job, destination)["GIT_ALLOW_PROTOCOL"], "https")
        self.assertNotIn("PLUGINSYNC_TOKEN", environment(job, destination))


class ProcessTests(Fixture):
    def test_bounded_log_and_real_process_capture(self):
        log = Journal(self.base / "output", limit=50)
        result = command([sys.executable, "-c", "print('x'*10000)"],
                         str(self.base), dict(os.environ), log, capture=True)
        log.close()
        self.assertEqual(len(result), 10000)
        self.assertLess((self.base / "output").stat().st_size, 100)

    def test_nonzero_script_reports_failure(self):
        log = Journal(self.base / "output")
        try:
            self.error(422, lambda: command([sys.executable, "-c", "raise SystemExit(17)"],
                                            str(self.base), dict(os.environ), log))
        finally:
            log.close()

    def test_timeout_kills_process(self):
        log = Journal(self.base / "output")
        try:
            self.error(422, lambda: command([sys.executable, "-c", "import time; time.sleep(10)"],
                                            str(self.base), dict(os.environ), log, timeout=0.1))
        finally:
            log.close()


class HttpTests(Fixture):
    def setUp(self):
        super().setUp()
        self.engine = Engine(self.store, self.base / "logs")
        self.server = Server(("127.0.0.1", 0), self.store, self.engine, TOKEN)
        self.thread = threading.Thread(target=self.server.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()
        super().tearDown()

    def request(self, method="GET", path="/v1/jobs", body=None, **headers):
        connection = http.client.HTTPConnection(*self.server.server_address, timeout=5)
        values = {"Authorization": "Bearer " + TOKEN}
        values.update(headers)
        if body is not None:
            body = json.dumps(body)
            values["Content-Type"] = "application/json"
        connection.request(method, path, body, values)
        response = connection.getresponse()
        data = response.read()
        self.assertEqual(response.getheader("Cache-Control"), "no-store")
        self.assertIsNone(response.getheader("Access-Control-Allow-Origin"))
        status = response.status
        connection.close()
        return status, json.loads(data)

    def test_authentication_before_list_and_mutation(self):
        self.assertEqual(self.request(Authorization="wrong")[0], 401)
        self.assertEqual(self.request("POST", body=self.config(), Authorization="")[0], 401)
        self.assertEqual(self.store.list()["total"], 0)

    def test_origin_and_unknown_routes_refused(self):
        self.assertEqual(self.request(Origin="https://untrusted.test")[0], 403)
        self.assertEqual(self.request(path="/v1/jobs/1/run?script=evil")[0], 404)
        self.assertEqual(self.request(path="/v1/jobs/1/run")[0], 405)
        self.assertEqual(self.request("PUT")[0], 405)

    def test_complete_api_flow(self):
        status, job = self.request("POST", body=self.config())
        self.assertEqual(status, 200)
        job_id = job["id"]
        self.assertEqual(self.request(path="/v1/jobs/" + str(job_id))[0], 200)
        self.assertEqual(self.request("POST", "/v1/jobs/" + str(job_id) + "/run", {})[0], 202)
        self.assertEqual(self.request("POST", "/v1/jobs/" + str(job_id) + "/run", {})[0], 409)
        self.assertEqual(self.request("DELETE", "/v1/jobs/" + str(job_id))[0], 409)
        self.assertEqual(self.request(path="/v1/jobs?page=1")[1]["total"], 1)

    def test_script_and_path_attack_rejected_at_api(self):
        self.assertEqual(self.request("POST", body=self.config(path="../../host"))[0], 400)
        self.assertEqual(self.request("POST", body=self.config(script="/etc/passwd"))[0], 400)
        self.assertEqual(self.store.list()["total"], 0)

    def test_invalid_secret_rejected_on_startup(self):
        with self.assertRaises(ValueError):
            Server(("127.0.0.1", 0), self.store, self.engine, "password")


if __name__ == "__main__":
    unittest.main()
