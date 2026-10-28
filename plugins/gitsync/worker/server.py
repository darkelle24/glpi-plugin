"""Private authenticated API; no Docker socket or Docker CLI. GPL-3.0-or-later."""
import hashlib
import hmac
import http.server
import json
import os
from pathlib import Path
import re
import threading
from core import Engine, Problem, Store


class Handler(http.server.BaseHTTPRequestHandler):
    server_version = "GitSync"
    sys_version = ""

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def log_message(self, *_):
        pass

    def reply(self, status, payload):
        data = json.dumps(payload, ensure_ascii=True).encode("ascii")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True
        if self.command != "HEAD":
            self.wfile.write(data)

    def dispatch(self):
        try:
            if self.headers.get("Origin") is not None:
                raise Problem(403, "Accès navigateur direct interdit.")
            auth = self.headers.get_all("Authorization", [])
            if len(auth) != 1 or not hmac.compare_digest(hashlib.sha256(auth[0].encode()).digest(), self.server.auth_hash):
                raise Problem(401, "Secret absent ou incorrect.")
            lengths = self.headers.get_all("Content-Length", [])
            if self.headers.get("Transfer-Encoding") or len(lengths) > 1:
                raise Problem(400, "Encodage de requête refusé.")
            length = int(lengths[0]) if lengths else 0
            if not 0 <= length <= 16384:
                raise Problem(413, "Requête trop volumineuse.")
            if self.command != "POST" and length:
                raise Problem(400, "Corps de requête inattendu.")
            payload = {}
            if length:
                if self.headers.get_content_type() != "application/json":
                    raise Problem(415, "JSON requis.")
                payload = json.loads(self.rfile.read(length))
                if not isinstance(payload, dict):
                    raise Problem(400, "Objet JSON requis.")
            store = self.server.store
            page = re.fullmatch(r"/v1/jobs(?:\?page=([1-9][0-9]{0,8}))?", self.path)
            item = re.fullmatch(r"/v1/jobs/([1-9][0-9]{0,12})(?:/(run|logs))?", self.path)
            if page:
                if self.command == "GET":
                    self.reply(200, store.list(int(page.group(1) or "1")))
                elif self.command == "POST":
                    user = self.headers.get("X-GLPI-User", "0")
                    if not re.fullmatch(r"[0-9]{1,12}", user):
                        raise Problem(400, "Identifiant utilisateur invalide.")
                    result = store.save(payload)
                    print(json.dumps({"event": "job_saved", "id": result["id"], "glpi_user": user}), flush=True)
                    self.reply(200, result)
                else:
                    raise Problem(405, "Méthode interdite.")
            elif item:
                job_id, action = int(item.group(1)), item.group(2)
                if self.command == "GET" and action is None:
                    self.reply(200, store.get(job_id))
                elif self.command == "GET" and action == "logs":
                    store.get(job_id)
                    log = self.server.engine.logs / (str(job_id) + ".log")
                    data = ""
                    if log.exists():
                        with log.open("rb") as stream:
                            stream.seek(0, 2)
                            stream.seek(max(0, stream.tell() - 65536))
                            data = stream.read(65536).decode("utf-8", errors="replace")
                    self.reply(200, {"logs": data})
                elif self.command == "POST" and action == "run":
                    if payload:
                        raise Problem(400, "Aucun paramètre attendu pour le lancement.")
                    user = self.headers.get("X-GLPI-User", "0")
                    if not re.fullmatch(r"[0-9]{1,12}", user):
                        raise Problem(400, "Identifiant utilisateur invalide.")
                    store.enqueue(job_id)
                    print(json.dumps({"event": "job_queued", "id": job_id, "glpi_user": user}), flush=True)
                    self.reply(202, {"status": "queued"})
                elif self.command == "DELETE" and action is None:
                    store.delete(job_id)
                    self.reply(200, {"status": "deleted"})
                else:
                    raise Problem(405, "Méthode interdite.")
            else:
                raise Problem(404, "Route inconnue.")
        except Problem as exc:
            self.reply(exc.status, {"error": exc.message})
        except (ValueError, UnicodeError, TypeError):
            self.reply(400, {"error": "Requête invalide."})
        except Exception:
            self.reply(500, {"error": "Erreur interne du service."})

    do_GET = dispatch
    do_POST = dispatch
    do_DELETE = dispatch
    do_PUT = dispatch
    do_PATCH = dispatch
    do_HEAD = dispatch
    do_OPTIONS = dispatch


class Server(http.server.ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 8

    def __init__(self, address, store, engine, token):
        if not re.fullmatch(r"[a-f0-9]{64}", token):
            raise ValueError("Secret must contain 64 lowercase hex characters")
        self.auth_hash = hashlib.sha256(("Bearer " + token).encode()).digest()
        self.store = store
        self.engine = engine
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(address, Handler)

    def process_request(self, request, address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, address)
        except Exception:
            self.slots.release()
            raise

    def process_request_thread(self, request, address):
        try:
            super().process_request_thread(request, address)
        finally:
            self.slots.release()


def main():
    os.umask(0o077)
    store = Store("/data/jobs.sqlite3", host_root=os.environ.get("PLUGINSYNC_HOST_REPO_ROOT", "/mnt/Apps/GLPI/repos"))
    engine = Engine(store, "/data/logs")
    token = Path("/run/secrets/pluginsync_token").read_text(encoding="ascii").strip()
    server = Server(("0.0.0.0", 8080), store, engine, token)
    engine.start()
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        engine.stop.set()
        server.server_close()


if __name__ == "__main__":
    main()
