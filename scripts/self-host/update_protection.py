"""Fixed host-owned drain, snapshot custody, and old-service recovery.

This engine never pulls an image, recreates a service, or runs migrations.
"""

from __future__ import annotations

import base64
import hashlib
import importlib.util
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import time

SERVICES = ("web", "queue", "backup-queue", "scheduler", "reverb")
CONTAINER = re.compile(r"[0-9a-f]{64}\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
STAGES = {"fence_intent", "drain_intent", "backup_intent", "resume_intent", "release_intent", "complete"}
APPLY_OPERATOR_SETTINGS = r'$a->make(App\Support\Settings\OperatorSettings::class)->applyOverrides();'
CAPTURE_BINDING = r'[$a["config"]->get("database"),$a["config"]->get("filesystems"),$a["config"]->get("wayfindr.attachments"),$a["config"]->get("wayfindr.backup"),$a["config"]->get("wayfindr.erasure"),App\Support\Backup\BackupService::appKeyFingerprints()]'
DB_QUIESCENCE_SQL = "SELECT COUNT(*) AS writers FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND backend_type = 'client backend' AND state IS DISTINCT FROM 'idle'"
DB_QUIESCENCE = r'''if(Illuminate\Support\Facades\DB::connection()->getDriverName()!=="pgsql"){exit(78);}$end=microtime(true)+120;do{$row=Illuminate\Support\Facades\DB::selectOne("''' + DB_QUIESCENCE_SQL + r'''");$n=$row->writers??null;if(!is_int($n)&&!(is_string($n)&&ctype_digit($n))){exit(78);}if((int)$n===0){break;}if(microtime(true)>=$end){exit(78);}usleep(250000);}while(true);'''
ENVIRONMENT = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "HOME": "/nonexistent",
               "DOCKER_CONFIG": "/etc/wayfindr-updater/docker"}


class DockerEngine:
    def __init__(self, config, api, state_dir):
        self.config, self.api = config, api
        self.directory = Path(config.value["install_dir"])
        self.state_dir = Path(state_dir)
        self.docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
        self.compose = self.docker + ["compose", "--project-name", config.value["compose_project"],
                        "--project-directory", str(self.directory), "--env-file", str(self.directory / ".env"),
                        "-f", str(self.directory / "compose.yml"), "-f", str(self.directory / "compose.updater.yml")]

    def call(self, args, reason="writer_unverified", timeout=30, json=False):
        try:
            code, raw = self.api.capture(self.docker + args, timeout=timeout)
        except Exception:
            raise self.api.Refusal(reason) from None
        if code != 0:
            raise self.api.Refusal(reason)
        return self.api.strict_json(raw, reason) if json else raw.decode("utf-8").strip()

    def inspect(self, container):
        # Deliberately omit Config.Env, mounts, customer labels and commands.
        template = '{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restarts":{{json .RestartCount}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"oneoff":{{json (index .Config.Labels "com.docker.compose.oneoff")}}}'
        return self.call(["inspect", "--format", template, container], json=True)

    def service_ids(self):
        result = {}
        for service in SERVICES:
            raw = self.call(["ps", "-a", "-q", "--no-trunc", "--filter", "label=com.docker.compose.project=" + self.config.value["compose_project"],
                             "--filter", "label=com.docker.compose.service=" + service, "--filter", "label=com.docker.compose.oneoff=False"])
            if not CONTAINER.fullmatch(raw):
                raise self.api.Refusal("writer_unverified")
            result[service] = raw
        if len(set(result.values())) != len(SERVICES):
            raise self.api.Refusal("writer_unverified")
        return result

    def dependencies(self):
        ids = set()
        for service in ("postgres", "redis"):
            raw = self.call(["ps", "-q", "--no-trunc", "--filter", "label=com.docker.compose.project=" + self.config.value["compose_project"],
                             "--filter", "label=com.docker.compose.service=" + service])
            if not CONTAINER.fullmatch(raw):
                raise self.api.Refusal("writer_unverified")
            state = self.inspect(raw)["state"]
            if state.get("Running") is not True or any(state.get(key) is not False for key in ("Paused", "Restarting", "Dead")) or state.get("Health", {}).get("Status") != "healthy":
                raise self.api.Refusal("writer_unverified")
            ids.add(raw)
        return ids

    def writers(self, allowed):
        raw = self.call(["ps", "-q", "--no-trunc", "--filter", "label=com.docker.compose.project=" + self.config.value["compose_project"]])
        running = set(raw.splitlines()) if raw else set()
        if any(not CONTAINER.fullmatch(item) for item in running) or running != set(allowed):
            raise self.api.Refusal("writer_unverified")

    def image_source(self, image):
        value = self.call(["image", "inspect", "--format", '{"env":{{json .Config.Env}},"id":{{json .Id}}}', image], json=True)
        if value.get("id") != image or not isinstance(value.get("env"), list):
            raise self.api.Refusal("source_changed")
        environment = dict(item.split("=", 1) for item in value["env"] if isinstance(item, str) and "=" in item)
        return {"version": environment.get("WAYFINDR_VERSION", "").removeprefix("v"), "commit": environment.get("WAYFINDR_COMMIT")}

    def window(self, container, operation, action):
        self.commands_settled(container)
        return self.call(["exec", container, "php", "artisan", "wayfindr:upgrade-window", operation, "--action=" + action, "--json"], "protection_failed", json=True)

    def commands_settled(self, container):
        # Closing a Docker client is not proof that its PHP exec has exited.
        # ExecIDs also cover created/not-yet-started execs. Check processes as
        # well: a failed daemon cancellation can remove an exec's metadata.
        value = self.call(["inspect", "--format", '{"execs":{{json .ExecIDs}},"running":{{json .State.Running}}}', container], "recovery_required", json=True)
        if not isinstance(value, dict) or set(value) != {"execs", "running"} or value["execs"] not in (None, []) or type(value["running"]) is not bool:
            raise self.api.Refusal("recovery_required")
        if value["running"]:
            processes = self.call(["top", container, "-eo", "args"], "recovery_required")
            if not processes.strip() or "wayfindr:upgrade-window" in processes:
                raise self.api.Refusal("recovery_required")

    def settled(self, ids, operation):
        for container in ids.values():
            self.commands_settled(container)
        if self.oneoff_active("wayfindr-updater-fence-" + operation):
            raise self.api.Refusal("recovery_required")

    def effective_keys(self, container):
        # Private root capture only. Never add these bytes to RPC receipts/logs.
        php = r'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' + APPLY_OPERATOR_SETTINGS + r'echo json_encode(["schema"=>1,"current"=>config("app.key"),"previous"=>(array)config("app.previous_keys",[]),"cipher"=>config("app.cipher"),"fingerprints"=>App\Support\Backup\BackupService::appKeyFingerprints(),"capture_binding_sha256"=>hash("sha256",serialize(' + CAPTURE_BINDING + r'))],JSON_THROW_ON_ERROR);'
        return self.call(["exec", container, "php", "-r", php], "custody_failed", json=True)

    def environment_map(self, items):
        if not isinstance(items, list):
            raise self.api.Refusal("source_changed")
        environment = {}
        for item in items:
            if not isinstance(item, str) or "=" not in item:
                raise self.api.Refusal("source_changed")
            key, data = item.split("=", 1)
            if not re.fullmatch(r"[a-zA-Z_][a-zA-Z0-9_]*", key) or key in environment:
                raise self.api.Refusal("source_changed")
            environment[key] = data
        return environment

    def runtime_environment(self, container):
        value = self.call(["inspect", "--format", '{"env":{{json .Config.Env}}}', container], "source_changed", json=True)
        return self.environment_map(value.get("env"))

    def environment_binding(self, container):
        # Compare configured variables before booting PHP in the backup oneoff.
        import json
        environment = self.runtime_environment(container)
        raw = json.dumps(environment, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()
        return {"sha256": hashlib.sha256(raw).hexdigest(), "keys": sorted(environment)}

    def require_source(self, ids, image):
        self.config.verify_files()
        code, raw = self.api.capture(self.compose + ["config", "--format", "json"])
        if code != 0:
            raise self.api.Refusal("source_changed")
        rendered = self.api.strict_json(raw, "source_changed")
        web = rendered.get("services", {}).get("web", {})
        overrides = web.get("environment")
        if not isinstance(overrides, dict):
            raise self.api.Refusal("source_changed")
        baked = self.call(["image", "inspect", "--format", '{"env":{{json .Config.Env}}}', image], "source_changed", json=True)
        expected = self.environment_map(baked.get("env"))
        for key, value in overrides.items():
            if not isinstance(key, str) or not re.fullmatch(r"[a-zA-Z_][a-zA-Z0-9_]*", key) or (value is not None and not isinstance(value, str)):
                raise self.api.Refusal("source_changed")
            if value is None:
                expected.pop(key, None)
            else:
                expected[key] = value
        if expected != self.runtime_environment(ids["web"]):
            raise self.api.Refusal("source_changed")
        mounts = web.get("volumes")
        if not isinstance(mounts, list):
            raise self.api.Refusal("source_changed")
        storage = [mount for mount in mounts if isinstance(mount, dict) and mount.get("target") == "/app/apps/server/storage"]
        if len(storage) != 1 or storage[0].get("type") != "volume" or storage[0].get("read_only", False) is not False:
            raise self.api.Refusal("source_changed")
        volume = rendered.get("volumes", {}).get(storage[0].get("source"), {}).get("name")
        if not isinstance(volume, str) or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}", volume):
            raise self.api.Refusal("source_changed")
        for container in ids.values():
            records = self.call(["inspect", "--format", '{"mounts":{{json .Mounts}}}', container], "source_changed", json=True).get("mounts")
            if not isinstance(records, list):
                raise self.api.Refusal("source_changed")
            actual = [mount for mount in records if isinstance(mount, dict) and mount.get("Destination") == "/app/apps/server/storage"]
            if len(actual) != 1 or actual[0].get("Type") != "volume" or actual[0].get("Name") != volume or actual[0].get("Driver") != "local" or actual[0].get("RW") is not True:
                raise self.api.Refusal("source_changed")
        self.config.verify_files()

    def drain(self, ids, timeout):
        # A negative daemon timeout waits for TERM completion without KILL.
        # Cancellation of this CLI does not cancel the daemon's pending stop.
        self.call(["stop", "--timeout=-1", "--signal=TERM", *ids.values()], "drain_timeout", timeout=timeout)

    def stop_supported(self):
        help_text = self.call(["stop", "--help"], "protection_unavailable")
        if "--timeout" not in help_text or "--signal" not in help_text:
            raise self.api.Refusal("protection_unavailable")

    def backup_name(self, operation):
        return "wayfindr-updater-backup-" + operation

    def backup_active(self, operation):
        return self.oneoff_active(self.backup_name(operation))

    def oneoff_active(self, name):
        raw = self.call(["ps", "-a", "-q", "--no-trunc", "--filter", "name=^/" + name + "$"])
        if not raw:
            return False
        if not CONTAINER.fullmatch(raw):
            raise self.api.Refusal("recovery_required")
        state = self.inspect(raw)["state"]
        # A created/paused/restarting container can still run its command later.
        return not (state.get("Status") == "exited" and state.get("Running") is False
                    and all(state.get(key) is False for key in ("Paused", "Restarting", "Dead", "OOMKilled"))
                    and not state.get("Error") and type(state.get("ExitCode")) is int and state["ExitCode"] >= 0)

    def backup(self, operation, image, context):
        # --entrypoint bypasses the normal migration/worker bootstrap entirely.
        import json
        self.require_source(context["containers"], image)
        names = base64.b64encode(json.dumps(context["environment_binding"]["keys"]).encode()).decode()
        # No credentials enter argv: only names and hashes. A stale .env or
        # cached database/storage configuration refuses before backup executes.
        php = r'$names=json_decode(base64_decode($argv[2],true),true,512,JSON_THROW_ON_ERROR);$e=[];foreach($names as $n){$v=getenv($n);if($v===false){exit(78);}$e[$n]=$v;}ksort($e);if(!hash_equals($argv[1],hash("sha256",json_encode($e,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)))){exit(78);}require "vendor/autoload.php";$a=require "bootstrap/app.php";$k=$a->make(Illuminate\Contracts\Console\Kernel::class);$k->bootstrap();' + APPLY_OPERATOR_SETTINGS + r'if(!hash_equals($argv[3],hash("sha256",serialize(' + CAPTURE_BINDING + r')))){exit(78);}' + DB_QUIESCENCE + r'$s=$k->handle(new Symfony\Component\Console\Input\ArrayInput(["command"=>"wayfindr:protective-backup","operation"=>$argv[4],"--json"=>true]),new Symfony\Component\Console\Output\ConsoleOutput());$k->terminate(new Symfony\Component\Console\Input\ArrayInput([]),$s);exit($s);'
        try:
            code, raw = self.api.capture(self.pinned_compose(operation, image) + ["run", "--no-deps", "--pull=never", "--entrypoint", "php",
                                    "--name", self.backup_name(operation), "-T", "web", "-r", php,
                                    context["environment_binding"]["sha256"], names, context["capture_binding_sha256"], operation], timeout=3600)
        except Exception:
            raise self.api.Refusal("backup_failed") from None
        if code != 0:
            raise self.api.Refusal("backup_failed")
        record = self.inspect(self.backup_name(operation))
        if record["image"] != image or record["state"].get("Running") is not False or record["state"].get("ExitCode") != 0:
            raise self.api.Refusal("backup_invalid")
        return self.api.strict_json(raw, "backup_invalid")

    def check_selected_image(self, image):
        selected = self.call(["image", "inspect", "--format", '{"id":{{json .Id}}}', self.config.value["image_reference"]], "source_changed", json=True)
        if selected.get("id") != image:
            raise self.api.Refusal("source_changed")

    def pinned_compose(self, operation, image):
        override = self.state_dir / "protection" / operation / "image.yml"
        self.api.trusted(override)
        if override.read_bytes() != self.api.encoded({"services": {"web": {"image": image}}}):
            raise self.api.Refusal("source_changed")
        return self.compose + ["-f", str(override)]

    def ensure_window(self, ids, operation, image):
        self.settled(ids, operation)
        if self.inspect(ids["web"])["state"].get("Running") is True:
            return self.window(ids["web"], operation, "enter")
        self.require_source(ids, image)
        code, raw = self.api.capture(self.pinned_compose(operation, image) + ["run", "--rm", "--no-deps", "--pull=never", "--entrypoint", "php",
                        "--name", "wayfindr-updater-fence-" + operation, "-T", "web", "artisan",
                        "wayfindr:upgrade-window", operation, "--action=enter", "--json"], timeout=30)
        if code != 0:
            raise self.api.Refusal("recovery_required")
        return self.api.strict_json(raw, "recovery_required")

    def start(self, ids):
        self.call(["start", *ids.values()], "recovery_required", timeout=90)

    def web_status(self, container):
        php = '$c=stream_context_create(["http"=>["ignore_errors"=>true,"timeout"=>5]]);@file_get_contents("http://127.0.0.1:8000/up",false,$c);preg_match("~^HTTP/\\S+ (\\d+)~",$http_response_header[0]??"",$m);echo $m[1]??"0";'
        value = self.call(["exec", container, "php", "-r", php], "recovery_required")
        return int(value) if value.isdigit() else 0

    def reverb_ready(self, container):
        php = '$s=@fsockopen("127.0.0.1",8080,$e,$m,3);if(!$s){exit(1);}fclose($s);'
        self.call(["exec", container, "php", "-r", php], "recovery_required")

    def copy_tar(self, container, source, destination, maximum):
        # Docker's stdout archive stays private; do not extract arbitrary paths.
        command = self.docker + ["cp", container + ":" + source, "-"]
        fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, "wb") as output:
                process = subprocess.Popen(command, stdout=output, stderr=subprocess.DEVNULL,
                                           stdin=subprocess.DEVNULL, env=ENVIRONMENT, start_new_session=True)
                deadline = time.monotonic() + 3600
                try:
                    while process.poll() is None:
                        if destination.stat().st_size > maximum or time.monotonic() > deadline:
                            raise self.api.Refusal("custody_failed")
                        time.sleep(0.1)
                    if process.returncode != 0 or destination.stat().st_size > maximum:
                        raise self.api.Refusal("custody_failed")
                    output.flush()
                    os.fsync(output.fileno())
                finally:
                    if process.poll() is None:
                        process.kill()  # Only the Docker client; never a writer.
                        process.wait()
        except Exception:
            destination.unlink(missing_ok=True)
            raise


class Protector:
    def __init__(self, config, journal, state_dir, api, engine=None, *, secure=True, drain_seconds=120):
        self.config, self.journal, self.root, self.api = config, journal, Path(state_dir), api
        self.engine = engine or DockerEngine(config, api, state_dir)
        self.secure, self.drain_seconds = secure, drain_seconds

    def refusal(self, reason):
        raise self.api.Refusal(reason)

    def persist(self, directory, context, stage):
        context["stage"] = stage
        self.api.atomic_write(directory / "state.json", context)

    def check_records(self, context, running):
        if self.engine.service_ids() != context["containers"]:
            self.refusal("writer_unverified")
        for service, container in context["containers"].items():
            record = self.engine.inspect(container)
            state = record.get("state", {})
            # A manual Docker start resets its restart counter. Its intent is
            # persisted first, so a crash before/after that reset is readable.
            restarts = {context["restarts"][service]}
            if service in context["resumed_services"]:
                restarts = {0} if state.get("Running") is True else {0, context["restarts"][service]}
            if record.get("id") != container or record.get("project") != self.config.value["compose_project"] or record.get("service") != service or record.get("image") != context["image"] or type(state.get("Running")) is not bool or (running is not None and state["Running"] is not running) or state.get("OOMKilled") is not False or any(state.get(key) is not False for key in ("Paused", "Restarting", "Dead")) or state.get("Error") or type(record.get("restarts")) is not int or record["restarts"] not in restarts:
                self.refusal("writer_unverified")
            if not state["Running"] and state.get("ExitCode") not in (0, 143):
                self.refusal("writer_unverified")

    def check_window(self, value, operation, source, held):
        keys = {"schema", "operation_id", "held", "ordinary_maintenance", "source", "ledger_supported"}
        if not isinstance(value, dict) or set(value) != keys or type(value["schema"]) is not int or value["schema"] != 1 or type(value["held"]) is not bool or value["held"] is not held or value["operation_id"] != (operation if held else None) or type(value["ordinary_maintenance"]) is not bool or value["source"] != {**source, "profile": "image"} or value["ledger_supported"] is not True:
            self.refusal("source_changed")
        if value["ordinary_maintenance"]:
            self.refusal("maintenance_present")

    def check_keys(self, value):
        if not isinstance(value, dict) or set(value) != {"schema", "current", "previous", "cipher", "fingerprints", "capture_binding_sha256"} or type(value["schema"]) is not int or value["schema"] != 1 or value["cipher"] not in ("AES-128-CBC", "AES-256-CBC", "AES-128-GCM", "AES-256-GCM") or not isinstance(value["previous"], list) or len(value["previous"]) > 128 or not isinstance(value["capture_binding_sha256"], str) or not re.fullmatch(r"[0-9a-f]{64}", value["capture_binding_sha256"]):
            self.refusal("custody_failed")
        fingerprints = []
        for key in [value["current"], *value["previous"]]:
            if not isinstance(key, str) or not key or len(key) > 1024:
                self.refusal("custody_failed")
            try:
                raw = base64.b64decode(key[7:], validate=True) if key.startswith("base64:") else key.encode("utf-8")
            except (ValueError, UnicodeError):
                self.refusal("custody_failed")
            if len(raw) != (16 if "128" in value["cipher"] else 32):
                self.refusal("custody_failed")
            fingerprints.append(hashlib.sha256(raw).hexdigest())
        if value["fingerprints"] != sorted(set(fingerprints)):
            self.refusal("custody_failed")
        return value

    def baseline(self, operation):
        self.config.verify_files()
        if "overlay_sha256" not in self.config.value:
            self.refusal("protection_unavailable")
        if self.secure:
            self.api.trusted(Path("/usr/bin/docker"))
            self.api.trusted(Path("/etc/wayfindr-updater/docker"), directory=True)
        self.engine.stop_supported()
        directory = Path(self.config.value["install_dir"])
        if (directory / ".upgrade.lock").exists() or (directory / ".upgrade.lock").is_symlink():
            self.refusal("operation_busy")
        ids = self.engine.service_ids()
        records = {service: self.engine.inspect(container) for service, container in ids.items()}
        image = records["web"].get("image")
        source = self.journal.status(operation)["operation"]["source"]
        if not isinstance(image, str) or not DIGEST.fullmatch(image) or self.engine.image_source(image) != source:
            self.refusal("source_changed")
        context = {"schema": 1, "installation_id": self.config.installation_id, "operation_id": operation,
                   "containers": ids, "image": image, "source": source,
                   "restarts": {service: records[service].get("restarts") for service in SERVICES}, "resumed_services": [], "stage": "fence_intent"}
        if any(type(count) is not int or count < 0 for count in context["restarts"].values()):
            self.refusal("writer_unverified")
        self.check_records(context, True)
        self.engine.check_selected_image(image)
        self.engine.require_source(ids, image)
        self.engine.writers(set(ids.values()) | self.engine.dependencies())
        self.engine.settled(ids, operation)
        self.check_window(self.engine.window(ids["web"], operation, "status"), operation, source, False)
        if self.engine.web_status(ids["web"]) != 200:
            self.refusal("writer_unverified")
        keys = self.check_keys(self.engine.effective_keys(ids["web"]))
        for service in SERVICES[1:]:
            others = self.check_keys(self.engine.effective_keys(ids[service]))
            if others["fingerprints"] != keys["fingerprints"] or others["cipher"] != keys["cipher"] or others["capture_binding_sha256"] != keys["capture_binding_sha256"]:
                self.refusal("custody_failed")
        context["key_fingerprints"] = keys["fingerprints"]
        context["capture_binding_sha256"] = keys["capture_binding_sha256"]
        context["environment_binding"] = self.engine.environment_binding(ids["web"])
        if len(self.api.encoded(context)) > 16384 or len(self.api.encoded(keys)) > 16384:
            self.refusal("custody_failed")
        return context, keys

    def load_context(self, directory, operation):
        if self.secure:
            self.api.trusted(directory / "state.json")
        value = self.api.read_object(directory / "state.json", 16384, "recovery_required")
        keys = {"schema", "installation_id", "operation_id", "containers", "image", "source", "restarts", "resumed_services", "stage", "key_fingerprints", "capture_binding_sha256", "environment_binding"}
        if set(value) != keys or type(value["schema"]) is not int or value["schema"] != 1 or value["installation_id"] != self.config.installation_id or value["operation_id"] != operation or not isinstance(value["stage"], str) or value["stage"] not in STAGES or not isinstance(value["containers"], dict) or set(value["containers"]) != set(SERVICES) or any(not isinstance(container, str) or not CONTAINER.fullmatch(container) for container in value["containers"].values()) or len(set(value["containers"].values())) != 5 or not isinstance(value["image"], str) or not DIGEST.fullmatch(value["image"]) or value["source"] != self.journal.status(operation)["operation"]["source"] or not isinstance(value["restarts"], dict) or set(value["restarts"]) != set(SERVICES) or any(type(count) is not int or count < 0 for count in value["restarts"].values()):
            self.refusal("recovery_required")
        if not isinstance(value["resumed_services"], list) or any(not isinstance(service, str) or service not in SERVICES for service in value["resumed_services"]) or value["resumed_services"] != sorted(set(value["resumed_services"])):
            self.refusal("recovery_required")
        key_file = directory / "keys.json"
        if self.secure:
            self.api.trusted(key_file)
        frozen = self.check_keys(self.api.read_object(key_file, 16384, "recovery_required"))
        binding = value["environment_binding"]
        if frozen["fingerprints"] != value["key_fingerprints"] or frozen["capture_binding_sha256"] != value["capture_binding_sha256"] or not isinstance(binding, dict) or set(binding) != {"sha256", "keys"} or not isinstance(binding["sha256"], str) or not re.fullmatch(r"[0-9a-f]{64}", binding["sha256"]) or not isinstance(binding["keys"], list) or not binding["keys"] or len(binding["keys"]) > 512 or any(not isinstance(key, str) or not re.fullmatch(r"[a-zA-Z_][a-zA-Z0-9_]*", key) for key in binding["keys"]) or binding["keys"] != sorted(set(binding["keys"])):
            self.refusal("recovery_required")
        return value

    def fsync_directory(self, directory):
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def copy_configuration(self, directory):
        self.config.verify_files()
        for name in (".env", "compose.yml", "compose.updater.yml", "install.sh"):
            source = Path(self.config.value["install_dir"]) / name
            if self.secure:
                self.api.trusted(source)
            fd = os.open(directory / ("config-" + name.lstrip(".")), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, "wb") as output, source.open("rb") as original:
                shutil.copyfileobj(original, output)
                output.flush()
                os.fsync(output.fileno())
        self.api.atomic_write(directory / "installation.json", self.config.value)
        self.config.verify_files()

    def custody(self, directory, operation, context, receipt):
        spec = importlib.util.spec_from_file_location("wayfindr_archive", Path(__file__).with_name("protection_archive.py"))
        archive_module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(archive_module)
        # Validate receipt before using any size in file allocation or arithmetic.
        try:
            archive_module.validate_receipt(receipt)
        except Exception:
            self.refusal("backup_invalid")
        if receipt["operation_id"] != operation or receipt["source"] != {**context["source"], "profile": "image"}:
            self.refusal("backup_invalid")
        size = receipt["archive_bytes"]
        if shutil.disk_usage(directory).free < size * 3 + 64 * 1024 * 1024:
            self.refusal("custody_failed")
        wrapper = directory / "archive-copy.tar"
        self.engine.copy_tar(self.engine.backup_name(operation), "/app/apps/server/storage/app/managed-updates/" + operation + "/archive.tar.gz", wrapper, size + 1024 * 1024)
        destination = directory / "archive.tar.gz"
        try:
            with tarfile.open(wrapper, "r|") as copied:
                member = copied.next()
                if member is None or member.name != "archive.tar.gz" or not member.isfile() or member.type not in (tarfile.REGTYPE, tarfile.AREGTYPE) or member.pax_headers or member.size != size:
                    self.refusal("custody_failed")
                fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                with os.fdopen(fd, "wb") as output:
                    shutil.copyfileobj(copied.extractfile(member), output)
                    output.flush()
                    os.fsync(output.fileno())
                if copied.next() is not None:
                    self.refusal("custody_failed")
            facts = archive_module.verify_archive(destination, receipt, context["key_fingerprints"])
            self.copy_configuration(directory)
            if receipt["coverage"]["erasure_ledger_present"]:
                ledger = directory / "erasure-ledger.tar"
                # A bounded custody file, never restored over newer erasures.
                self.engine.copy_tar(context["containers"]["web"], "/app/apps/server/storage/app/erasure-ledger", ledger, 64 * 1024 * 1024)
                with tarfile.open(ledger, "r|") as archive:
                    seen = set()
                    for member in archive:
                        path = PurePosixPath(member.name)
                        if member.name in seen or path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != "erasure-ledger" or member.pax_headers or member.type not in (tarfile.REGTYPE, tarfile.AREGTYPE, tarfile.DIRTYPE):
                            self.refusal("custody_failed")
                        seen.add(member.name)
                    if "erasure-ledger" not in seen and "erasure-ledger/" not in seen:
                        self.refusal("custody_failed")
            self.api.atomic_write(directory / "receipt.json", receipt)
            custody = {"schema": 1, "operation_id": operation, "erasure_ledger_present": receipt["coverage"]["erasure_ledger_present"], "files": {}}
            for path in directory.iterdir():
                if path.name.startswith("config-") or path.name in {"installation.json", "erasure-ledger.tar", "keys.json"}:
                    with path.open("rb") as original:
                        custody["files"][path.name] = hashlib.file_digest(original, "sha256").hexdigest()
            self.api.atomic_write(directory / "custody.json", custody)
            self.fsync_directory(directory)
            return facts
        except Exception:
            self.refusal("custody_failed")
        finally:
            wrapper.unlink(missing_ok=True)

    def recover(self, directory, operation, context, *, before_release=None):
        self.config.verify_files()
        self.engine.settled(context["containers"], operation)
        if self.engine.backup_active(operation):
            self.refusal("recovery_required")
        # Never race a pending daemon stop. A partially drained set stays held.
        running = [self.engine.inspect(container)["state"].get("Running") for container in context["containers"].values()]
        if context["stage"] in {"drain_intent", "backup_intent"} and any(running):
            self.refusal("recovery_required")
        mixed = any(running) and not all(running)
        if mixed and context["stage"] not in {"resume_intent", "release_intent", "complete"}:
            self.refusal("recovery_required")
        self.check_records(context, None if mixed else bool(all(running)))
        # Establish the fence through a PHP-only old-image oneoff before any
        # ordinary entrypoint can start (and potentially AUTO_MIGRATE).
        self.check_window(self.engine.ensure_window(context["containers"], operation, context["image"]), operation, context["source"], True)
        if not all(running):
            stopped = {service: container for (service, container), active in zip(context["containers"].items(), running) if not active}
            context["resumed_services"] = sorted(set(context["resumed_services"]) | set(stopped))
            self.persist(directory, context, "resume_intent")
            self.engine.start(stopped)
        deadline = time.monotonic() + 90
        while True:
            try:
                self.check_records(context, True)
                self.engine.writers(set(context["containers"].values()) | self.engine.dependencies())
                value = self.engine.window(context["containers"]["web"], operation, "status")
                self.check_window(value, operation, context["source"], True)
                if self.engine.web_status(context["containers"]["web"]) != 503:
                    self.refusal("recovery_required")
                self.engine.reverb_ready(context["containers"]["reverb"])
                break
            except Exception:
                if time.monotonic() >= deadline:
                    self.refusal("recovery_required")
                time.sleep(1)
        if before_release is not None:
            before_release()
        self.journal.protection_checkpoint(operation, "services_resumed", {"phase": "resuming", "services_recovered": True, "hold_owned": value["held"]})
        if value["held"]:
            self.engine.settled(context["containers"], operation)
            self.persist(directory, context, "release_intent")
            self.check_window(self.engine.window(context["containers"]["web"], operation, "release"), operation, context["source"], False)
        self.engine.settled(context["containers"], operation)
        # Success requires an observed serving baseline after release.
        if self.engine.web_status(context["containers"]["web"]) != 200:
            self.refusal("recovery_required")
        self.persist(directory, context, "complete")
        evidence = self.journal.status(operation)["operation"]["protection"]
        verified = evidence["custody_verified"]
        final = {"phase": "verified" if verified else "resuming", "hold_owned": False}
        if verified:
            final["error"] = None
        self.journal.protection_checkpoint(operation, "protection_released", final)

    def capture(self, operation, *, retain_hold=False):
        """Capture a fresh recovery point; managed apply keeps original writers stopped."""
        directory = self.root / "protection" / operation
        context, keys = self.baseline(operation)
        parent = directory.parent
        if not parent.exists():
            parent.mkdir(mode=0o700)
            self.fsync_directory(parent.parent)
        if self.secure:
            self.api.trusted(parent, directory=True)
        directory.mkdir(mode=0o700)
        self.fsync_directory(parent)
        self.api.atomic_write(directory / "keys.json", keys)
        self.persist(directory, context, "fence_intent")
        self.api.atomic_write(directory / "image.yml", {"services": {"web": {"image": context["image"]}}})
        self.engine.settled(context["containers"], operation)
        self.check_window(self.engine.window(context["containers"]["web"], operation, "enter"), operation, context["source"], True)
        self.journal.protection_checkpoint(operation, "fenced", {"phase": "draining", "hold_owned": True, "source_image_id": context["image"]})
        self.persist(directory, context, "drain_intent")
        self.engine.drain(context["containers"], self.drain_seconds)
        self.check_records(context, False)
        self.engine.writers(self.engine.dependencies())
        self.journal.protection_checkpoint(operation, "drained", {"phase": "backing_up"})
        self.persist(directory, context, "backup_intent")
        self.config.verify_files()
        receipt = self.engine.backup(operation, context["image"], context)
        facts = self.custody(directory, operation, context, receipt)
        self.journal.protection_checkpoint(operation, "backup_verified", {"phase": "captured" if retain_hold else "resuming", "archive_sha256": receipt["archive_sha256"],
            "manifest_sha256": receipt["manifest_sha256"], "archive_bytes": receipt["archive_bytes"],
            "local_attachment_disks": facts["local_attachment_disks"], "external_attachment_disks": receipt["coverage"]["external_attachment_disks"],
            "offsite_uploaded": receipt["coverage"]["offsite_uploaded"], "offsite_verification": receipt["coverage"]["offsite_verification"], "custody_verified": True})
        return context

    def __call__(self, operation, recovery=False):
        directory = self.root / "protection" / operation
        context = None
        reason = "protection_verified"
        try:
            if recovery:
                context = self.load_context(directory, operation)
                reason = self.journal.status(operation)["operation"]["protection"]["error"] or "protection_failed"
            else:
                context = self.capture(operation)
            self.recover(directory, operation, context)
            self.journal.protection_finish(operation, "protection_verified" if self.journal.status(operation)["operation"]["protection"]["phase"] == "verified" else reason, True)
        except Exception as failure:
            reason = failure.reason if isinstance(failure, self.api.Refusal) else "protection_failed"
            # capture() can fail after its durable fence intent. Load that
            # context before cleanup; never mistake an unknown effect for no effect.
            if context is None and (directory / "state.json").is_file():
                try:
                    context = self.load_context(directory, operation)
                except Exception:
                    self.journal.protection_finish(operation, reason, False)
                    return
            # Before a persisted fence intent no app side effect was possible.
            if context is None or not (directory / "state.json").is_file():
                if recovery:
                    self.journal.protection_finish(operation, reason, False)
                else:
                    self.journal.protection_finish(operation, reason, True)
                return
            try:
                self.recover(directory, operation, context)
            except Exception:
                hold_owned = None
                try:
                    self.check_window(self.engine.ensure_window(context["containers"], operation, context["image"]), operation, context["source"], True)
                    hold_owned = True
                except Exception:
                    pass  # Uncertainty still holds the durable host operation.
                self.journal.protection_finish(operation, reason, False, hold_owned=hold_owned)
            else:
                verified = self.journal.status(operation)["operation"]["protection"]["phase"] == "verified"
                self.journal.protection_finish(operation, "protection_verified" if verified else reason, True)
