"""Plugin Sync 2 — persistent jobs and Git execution. GPL-3.0-or-later."""
from contextlib import closing
from datetime import datetime, timezone
import json
import os
from pathlib import Path, PurePosixPath
import re
import signal
import sqlite3
import subprocess
import threading
import time
from urllib.parse import urlsplit


class Problem(Exception):
    def __init__(self, status, message):
        self.status = status
        self.message = message
        super().__init__(message)


class Connection(sqlite3.Connection):
    def __exit__(self, *args):
        try:
            return super().__exit__(*args)
        finally:
            self.close()


def utc(value=None):
    return datetime.fromtimestamp(time.time() if value is None else value, timezone.utc).isoformat(timespec="seconds")


class Store:
    def __init__(self, database, repo_root="/repos", host_root="/mnt/Apps/GLPI/repos", clock=time.time):
        self.database = str(database)
        self.root = Path(repo_root)
        self.host_root = str(PurePosixPath(host_root)).rstrip("/")
        if not self.host_root.startswith("/") or self.host_root == "":
            raise ValueError("An absolute host repository root is required")
        self.clock = clock
        self.lock = threading.RLock()
        Path(database).parent.mkdir(parents=True, exist_ok=True)
        self.root.mkdir(parents=True, exist_ok=True)
        with self.connect() as db:
            db.execute("""CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, url TEXT NOT NULL,
                branch TEXT NOT NULL, path TEXT NOT NULL, script TEXT NOT NULL,
                auto_pull INTEGER NOT NULL, interval_minutes INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'idle', last_attempt TEXT,
                finished_at TEXT, next_run REAL, queued_at REAL,
                mode TEXT, message TEXT NOT NULL DEFAULT '', last_commit TEXT NOT NULL DEFAULT ''
            )""")
        # Never replay a script after an uncertain process/container interruption.
        with self.lock, self.connect() as db:
            db.execute("""UPDATE jobs SET status='error', message=?,
                finished_at=?, next_run=? + interval_minutes * 60
                WHERE status IN ('queued','running')""",
                ("Service redémarré pendant une tentative. Vérifiez le résultat avant de relancer.",
                 utc(self.clock()), self.clock()))

    def connect(self):
        db = sqlite3.connect(self.database, timeout=10, factory=Connection)
        db.row_factory = sqlite3.Row
        return db

    def destination(self, relative):
        candidate = self.root / relative
        # Reject symlinks in every existing component, including the destination.
        current = self.root
        if current.is_symlink():
            raise Problem(400, "La racine des dépôts ne doit pas être un lien symbolique.")
        for part in PurePosixPath(relative).parts:
            current = current / part
            if current.is_symlink():
                raise Problem(400, "Un lien symbolique est interdit dans le chemin de clonage.")
        if not candidate.resolve().is_relative_to(self.root.resolve()) or candidate.resolve() == self.root.resolve():
            raise Problem(400, "Le chemin doit rester sous la racine autorisée.")
        return candidate

    def validate(self, value):
        if not isinstance(value, dict):
            raise Problem(400, "Configuration invalide.")
        allowed = {"id", "name", "url", "branch", "path", "script", "auto_pull", "interval_minutes"}
        if set(value) - allowed:
            raise Problem(400, "Paramètre de configuration inconnu.")
        result = {}
        for key, default in [("name", ""), ("url", ""), ("branch", "main"), ("path", ""), ("script", "")]:
            raw = value.get(key, default)
            if not isinstance(raw, str):
                raise Problem(400, "Les champs texte doivent être des chaînes.")
            result[key] = raw.strip()
        if not result["name"] or len(result["name"]) > 80 or any(ord(c) < 32 for c in result["name"]):
            raise Problem(400, "Indiquez un nom de 1 à 80 caractères.")
        url = result["url"]
        try:
            parsed = urlsplit(url)
            valid = (parsed.scheme == "https" and parsed.hostname and parsed.username is None
                     and parsed.password is None and parsed.port in (None, 443)
                     and parsed.path not in ("", "/") and not parsed.query and not parsed.fragment)
        except ValueError:
            valid = False
        if not valid or len(url) > 2048 or any(c.isspace() or ord(c) < 32 for c in url):
            raise Problem(400, "Utilisez une URL HTTPS sans identifiants, paramètres ni port personnalisé.")
        branch = result["branch"]
        if (not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._/-]{0,99}", branch)
                or ".." in branch or "//" in branch or branch.endswith(("/", ".", ".lock"))
                or any(p.startswith(".") or p.endswith(".lock") for p in branch.split("/"))):
            raise Problem(400, "Le nom de branche est invalide.")
        path = result["path"]
        if path.startswith(self.host_root + "/"):
            path = path[len(self.host_root) + 1:]
        if (not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._/-]{0,199}", path)
                or any(p in ("", ".", "..") or p.startswith(".") for p in path.split("/"))):
            raise Problem(400, "Choisissez un sous-dossier simple sous " + self.host_root + ".")
        self.destination(path)
        result["path"] = path
        script = result["script"]
        if script and (not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9._/-]{0,199}", script)
                       or any(p in ("", ".", "..", ".git") for p in script.split("/"))):
            raise Problem(400, "Le script doit être un chemin relatif dans le dépôt, par exemple deploy.sh.")
        automatic = value.get("auto_pull", False)
        interval = value.get("interval_minutes", 15)
        if type(automatic) is not bool or type(interval) is not int or not 5 <= interval <= 10080:
            raise Problem(400, "L'intervalle doit être compris entre 5 et 10080 minutes.")
        result.update(auto_pull=int(automatic), interval_minutes=interval)
        return result

    @staticmethod
    def public(row, host_root):
        result = dict(row)
        result["auto_pull"] = bool(result["auto_pull"])
        result["host_path"] = host_root + "/" + result["path"]
        result["next_run_at"] = utc(result["next_run"]) if result["auto_pull"] and result["next_run"] else None
        return result

    def get(self, job_id):
        with self.lock, closing(self.connect()) as db:
            row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
        if row is None:
            raise Problem(404, "Dépôt introuvable.")
        return self.public(row, self.host_root)

    def list(self, page=1):
        page = max(1, page)
        with self.lock, closing(self.connect()) as db:
            total = db.execute("SELECT COUNT(*) FROM jobs").fetchone()[0]
            pages = max(1, (total + 11) // 12)
            page = min(page, pages)
            rows = db.execute("SELECT * FROM jobs ORDER BY id LIMIT 12 OFFSET ?", ((page - 1) * 12,)).fetchall()
            counts = dict(db.execute("SELECT status, COUNT(*) FROM jobs GROUP BY status").fetchall())
        return {"jobs": [self.public(row, self.host_root) for row in rows], "total": total,
                "page": page, "pages": pages, "counts": counts, "host_root": self.host_root}

    def save(self, value):
        fields = self.validate(value)
        job_id = value.get("id", 0)
        if type(job_id) is not int or job_id < 0:
            raise Problem(400, "Identifiant invalide.")
        with self.lock, self.connect() as db:
            if job_id:
                old = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
                if old is None:
                    raise Problem(404, "Dépôt introuvable.")
                if old["status"] in ("queued", "running"):
                    raise Problem(409, "Attendez la fin de cette exécution pour modifier le dépôt.")
            for row in db.execute("SELECT id,path FROM jobs WHERE id != ?", (job_id,)):
                a, b = PurePosixPath(fields["path"]), PurePosixPath(row["path"])
                if a == b or a.is_relative_to(b) or b.is_relative_to(a):
                    raise Problem(409, "Ce chemin chevauche celui d'un autre dépôt.")
            next_run = self.clock() + fields["interval_minutes"] * 60 if fields["auto_pull"] else None
            params = list(fields.values()) + [next_run]
            if job_id:
                keys = ",".join(key + "=?" for key in fields)
                db.execute("UPDATE jobs SET " + keys + ",next_run=? WHERE id=?", params + [job_id])
            else:
                keys = ",".join(fields) + ",next_run"
                cursor = db.execute("INSERT INTO jobs (" + keys + ") VALUES (" + ",".join("?" for _ in params) + ")", params)
                job_id = cursor.lastrowid
        return self.get(job_id)

    def enqueue(self, job_id, mode="manual"):
        with self.lock, self.connect() as db:
            row = db.execute("SELECT status FROM jobs WHERE id=?", (job_id,)).fetchone()
            if row is None:
                raise Problem(404, "Dépôt introuvable.")
            if row["status"] in ("queued", "running"):
                raise Problem(409, "Ce dépôt est déjà en cours ou en attente.")
            db.execute("UPDATE jobs SET status='queued',queued_at=?,mode=?,message=? WHERE id=?",
                       (self.clock(), mode, "En attente d'exécution.", job_id))

    def due(self):
        with self.lock, self.connect() as db:
            db.execute("""UPDATE jobs SET status='queued', queued_at=?, mode='auto',
                message='Synchronisation automatique en attente.'
                WHERE auto_pull=1 AND next_run<=? AND status NOT IN ('queued','running')""",
                (self.clock(), self.clock()))

    def claim(self):
        with self.lock, self.connect() as db:
            row = db.execute("SELECT * FROM jobs WHERE status='queued' ORDER BY queued_at,id LIMIT 1").fetchone()
            if row is None:
                return None
            db.execute("UPDATE jobs SET status='running',last_attempt=?,finished_at=NULL,message=? WHERE id=?",
                       (utc(self.clock()), "Synchronisation en cours.", row["id"]))
        return self.get(row["id"])

    def finish(self, job_id, success, message, commit=""):
        with self.lock, self.connect() as db:
            db.execute("""UPDATE jobs SET status=?, message=?, finished_at=?,
                last_commit=CASE WHEN ?='' THEN last_commit ELSE ? END,
                next_run=CASE WHEN auto_pull=1 THEN ? + interval_minutes*60 ELSE NULL END WHERE id=?""",
                ("success" if success else "error", message[:500], utc(self.clock()),
                 commit, commit, self.clock(), job_id))

    def delete(self, job_id):
        with self.lock, self.connect() as db:
            row = db.execute("SELECT status FROM jobs WHERE id=?", (job_id,)).fetchone()
            if row is None:
                raise Problem(404, "Dépôt introuvable.")
            if row["status"] in ("queued", "running"):
                raise Problem(409, "Attendez la fin de l'exécution avant de retirer ce dépôt.")
            db.execute("DELETE FROM jobs WHERE id=?", (job_id,))
        # Deliberately retain cloned files. No recursive deletion via the web UI.


class Journal:
    def __init__(self, path, limit=262144):
        self.file = open(path, "wb")
        self.limit = limit
        self.used = 0
        self.truncated = False
        self.lock = threading.Lock()

    def write(self, content):
        data = content.encode("utf-8", errors="replace") if isinstance(content, str) else content
        with self.lock:
            remaining = self.limit - self.used
            if remaining > 0:
                self.file.write(data[:remaining])
                self.used += min(remaining, len(data))
            if len(data) > remaining and not self.truncated:
                self.file.write(b"\n[Journal tronque a 256 Kio]\n")
                self.truncated = True
            self.file.flush()

    def close(self):
        self.file.close()


def environment(job, destination):
    return {
        "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/tmp", "LANG": "C.UTF-8",
        "GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_NOSYSTEM": "1",
        "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_ALLOW_PROTOCOL": "https",
        "PLUGINS_PATH": "/glpi/plugins", "MARKETPLACE_PATH": "/glpi/marketplace",
        "REPO_DIR": str(destination), "REPO_URL": job["url"], "REPO_BRANCH": job["branch"],
    }


def command(argv, cwd, env, journal, timeout=300, capture=False):
    journal.write("\n> " + " ".join(argv) + "\n")
    process = subprocess.Popen(argv, cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                               shell=False, start_new_session=os.name != "nt",
                               umask=0o022 if os.name != "nt" else -1)
    output = bytearray()

    def drain():
        while True:
            data = process.stdout.read(4096)
            if not data:
                break
            journal.write(data)
            if capture and len(output) < 65536:
                output.extend(data[:65536 - len(output)])

    reader = threading.Thread(target=drain, daemon=True)
    reader.start()
    try:
        code = process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        if os.name == "nt":
            process.kill()
        else:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        process.wait()
        reader.join(timeout=5)
        process.stdout.close()
        raise Problem(422, "Délai dépassé pour Git ou le script. Vérifiez le journal.")
    reader.join(timeout=5)
    if reader.is_alive():
        if os.name != "nt":
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        reader.join(timeout=5)
        if not reader.is_alive():
            process.stdout.close()
        raise Problem(422, "Le script a laissé un processus en arrière-plan. Consultez le journal.")
    process.stdout.close()
    if code != 0:
        raise Problem(422, "Échec de la commande (code " + str(code) + "). Consultez le journal.")
    return output.decode("utf-8", errors="replace").strip()


def synchronize(store, job, journal, run=command):
    destination = store.destination(job["path"])
    env = environment(job, destination)
    git = ["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "http.followRedirects=false",
           "-c", "credential.helper=", "-c", "protocol.file.allow=never",
           "-c", "protocol.ext.allow=never"]
    fresh = not destination.exists() or (destination.is_dir() and not any(destination.iterdir()))
    before = ""
    if fresh:
        destination.parent.mkdir(parents=True, exist_ok=True)
        run(git + ["clone", "--no-recurse-submodules", "--single-branch", "--branch",
                   job["branch"], "--", job["url"], str(destination)], str(store.root), env, journal)
    else:
        if not (destination / ".git").is_dir() or (destination / ".git").is_symlink():
            raise Problem(422, "Le dossier existe mais ne contient pas un clone Git géré. Aucun fichier supprimé.")
        def query(args):
            return run(git + ["-C", str(destination)] + args, str(destination), env, journal, capture=True)
        if query(["rev-parse", "--show-toplevel"]) != str(destination.resolve()):
            raise Problem(422, "La racine Git ne correspond pas au chemin configuré.")
        if query(["remote", "get-url", "origin"]) != job["url"]:
            raise Problem(422, "L'URL du clone existant diffère. Choisissez un nouveau chemin ou corrigez le clone manuellement.")
        if query(["symbolic-ref", "--short", "HEAD"]) != job["branch"]:
            raise Problem(422, "La branche du clone existant diffère. Aucun changement forcé.")
        if query(["status", "--porcelain"]):
            raise Problem(422, "Le clone contient des modifications locales. Aucun écrasement automatique.")
        before = query(["rev-parse", "HEAD"])
        query(["fetch", "--no-tags", "origin", job["branch"]])
        query(["merge", "--ff-only", "FETCH_HEAD"])
    commit = run(git + ["-C", str(destination), "rev-parse", "HEAD"],
                 str(destination), env, journal, capture=True)
    changed = fresh or before != commit
    if not re.fullmatch(r"[a-f0-9]{40,64}", commit):
        raise Problem(422, "Révision Git invalide.")
    execute_script = bool(job["script"]) and (
        job["mode"] == "manual" or changed or job.get("last_commit", "") != commit
    )
    if execute_script:
        script = destination / job["script"]
        if not script.is_file() or not script.resolve().is_relative_to(destination.resolve()):
            raise Problem(422, "Script absent ou situé hors du dépôt.")
        run(["/bin/sh", str(script.resolve())], str(destination), env, journal, timeout=900)
    if execute_script:
        message = "Dépôt synchronisé et script terminé avec succès."
    elif not changed:
        message = "Dépôt déjà à jour. Aucun script exécuté automatiquement."
    else:
        message = "Dépôt synchronisé avec succès."
    return message, commit


class Engine:
    def __init__(self, store, logs, runner=synchronize):
        self.store = store
        self.logs = Path(logs)
        self.logs.mkdir(parents=True, exist_ok=True)
        self.runner = runner
        self.stop = threading.Event()
        self.thread = None

    def step(self):
        self.store.due()
        job = self.store.claim()
        if job is None:
            return False
        try:
            journal = Journal(self.logs / (str(job["id"]) + ".log"))
        except OSError:
            self.store.finish(job["id"], False, "Impossible d'ouvrir le journal. Vérifiez l'espace disque et les permissions.")
            return True
        journal.write(utc() + " — " + job["name"] + " (" + job["mode"] + ")\n")
        try:
            message, commit = self.runner(self.store, job, journal)
            self.store.finish(job["id"], True, message, commit)
        except Exception as exc:
            message = exc.message if isinstance(exc, Problem) else "Erreur du service : " + type(exc).__name__ + ". Consultez le journal."
            journal.write("\nERREUR : " + message + "\n")
            self.store.finish(job["id"], False, message)
        finally:
            journal.close()
        print(json.dumps({"event": "job_finished", "id": job["id"],
                          "status": self.store.get(job["id"])["status"], "time": utc()}), flush=True)
        return True

    def start(self):
        def loop():
            while not self.stop.is_set():
                try:
                    worked = self.step()
                except Exception as exc:
                    print(json.dumps({"event": "engine_error", "type": type(exc).__name__}), flush=True)
                    worked = False
                if not worked:
                    self.stop.wait(3)
        self.thread = threading.Thread(target=loop, daemon=True)
        self.thread.start()
