"""Root-only, journal-owned image apply and conservative interruption recovery.

All application writers stay fenced from the protective snapshot through target
verification. An intent to invoke migration permanently prohibits source fallback.
Downloaded code is never executed on the host. No database restore or pruning is
part of this executor.
"""

from __future__ import annotations

import base64
import copy
from datetime import datetime
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import platform
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


SERVICES = ("web", "queue", "backup-queue", "scheduler", "reverb")
HASH = re.compile(r"[0-9a-f]{64}\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
STAGES = {"download_intent", "downloaded", "protect_intent", "protected", "migration_intent",
          "migrated", "restart_intent", "runtime_verified", "configuration_commit_intent",
          "configuration_committed", "release_intent", "complete", "fallback"}
PROMOTION_STAGES = {"configuration_commit_intent", "configuration_committed", "release_intent", "complete"}
STATE_KEYS = {"schema", "installation_id", "operation_id", "plan_id", "source", "target", "stage",
              "started", "old_config", "new_config", "overlay", "source_context", "dependencies",
              "layouts", "origin", "artifacts", "target_ids", "creation_intents", "migration_receipt_sha256",
              "runtime_receipt_sha256"}
RECEIPT_KEYS = {"schema", "operation_id", "plan_id", "phase", "target", "binding_sha256",
                "manifest_sha256", "history_sha256", "migrations_sha256", "release_state_sha256",
                "pending_migrations", "guards_clear", "database_verified", "redis_verified", "hold_owned"}


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def sibling(name):
    spec = importlib.util.spec_from_file_location("wayfindr_" + name, Path(__file__).with_name(name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def private_object(path, api, maximum=1_000_000, secure=True):
    if secure:
        try:
            api.trusted(path)
            if path.stat().st_mode & 0o077:
                raise api.Refusal("recovery_required")
        except OSError:
            raise api.Refusal("recovery_required") from None
    return api.read_object(path, maximum, "recovery_required")


def verify_transition(config, state_dir, api):
    """Allow daemon startup only for a recorded, already verified promotion.

    This exception cannot authorize a fresh apply or accept an edited .env/base
    compose. The active journal and both private receipts must agree with the
    precise old/new configuration pair and overlay bytes.
    """
    root = Path(state_dir)
    journal = private_object(root / "journal.json", api, 4_000_000)
    api.validate_journal(journal, config.installation_id)
    operation = journal["active_operation"]
    if operation is None:
        raise api.Refusal("configuration_changed")
    public = journal["operations"][operation]
    directory = root / "apply" / operation
    state = private_object(directory / "state.json", api)
    if (set(state) != STATE_KEYS or type(state["schema"]) is not int or state["schema"] != 1 or state["installation_id"] != config.installation_id
            or state["operation_id"] != operation or state["stage"] not in PROMOTION_STAGES
            or state["plan_id"] != public["plan_id"] or state["target"] != public["target"]
            or state["source"] != public["source"] or "apply" not in public
            or not public["mutation_started"] or not public["apply"]["migration_verified"]
            or not public["apply"]["services_verified"] or not public["apply"]["origin_verified"]):
        raise api.Refusal("configuration_changed")
    old, new = state["old_config"], state["new_config"]
    api.Configuration(old, config.token)
    api.Configuration(new, config.token)
    if config.value not in (old, new) or {k: v for k, v in old.items() if k not in {"image_reference", "overlay_sha256"}} != {k: v for k, v in new.items() if k not in {"image_reference", "overlay_sha256"}}:
        raise api.Refusal("configuration_changed")
    artifacts = state["artifacts"]
    expected_image = "ghcr.io/adamgreenwell/wayfindr:" + state["target"]["version"] + "@" + state["target"]["image_digest"]
    if new["image_reference"] != expected_image or new["overlay_sha256"] != digest(api.encoded(state["overlay"])) or artifacts["index_digest"] != state["target"]["image_digest"]:
        raise api.Refusal("configuration_changed")
    for name, field in (("migration.json", "migration_receipt_sha256"), ("runtime.json", "runtime_receipt_sha256")):
        receipt = private_object(directory / name, api)
        if digest(api.encoded(receipt)) != state[field] or state[field] != public["apply"][field]:
            raise api.Refusal("configuration_changed")
    install = Path(old["install_dir"])
    api.trusted(install, directory=True)
    for filename, key in ((".env", "env_sha256"), ("compose.yml", "compose_sha256"), ("install.sh", "installer_sha256")):
        path = install / filename
        api.trusted(path)
        if digest(path.read_bytes()) != old[key]:
            raise api.Refusal("configuration_changed")
    api.trusted(install / ".updater-enrolled")
    if (install / ".updater-enrolled").read_text().strip() != config.installation_id:
        raise api.Refusal("configuration_changed")
    overlay = install / "compose.updater.yml"
    api.trusted(overlay)
    if digest(overlay.read_bytes()) not in {old["overlay_sha256"], new["overlay_sha256"]}:
        raise api.Refusal("configuration_changed")
    return True


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def valid_origin(origin):
    if not isinstance(origin, str) or len(origin) > 2048 or any(ord(c) <= 32 for c in origin):
        return False
    try:
        parsed = urllib.parse.urlsplit(origin)
        return bool(parsed.scheme in {"http", "https"} and parsed.hostname and parsed.port != 0
                    and parsed.username is None and parsed.password is None and parsed.path in {"", "/"}
                    and not parsed.query and not parsed.fragment)
    except ValueError:
        return False


def origin_response(origin, challenge, opener):
    request = urllib.request.Request(origin + "/up", headers={"X-Wayfindr-Update-Challenge": challenge, "Cache-Control": "no-cache"})
    try:
        response = opener.open(request, timeout=10)
    except urllib.error.HTTPError as failure:
        response = failure
    with response:
        proofs = response.headers.get_all("X-Wayfindr-Update-Proof")
        if not isinstance(proofs, list) or len(proofs) != 1 or not isinstance(proofs[0], str) or not HASH.fullmatch(proofs[0]):
            raise ValueError()
        return {"status": response.code, "proof": proofs[0]}


def bounded_origin_response(origin, challenge):
    # A network-only child bounds DNS and slow response headers. Killing it
    # cannot race application commands. No application key enters argv/stdin.
    if not valid_origin(origin) or not HASH.fullmatch(challenge):
        raise ValueError()
    process = subprocess.Popen(["/usr/bin/python3", str(Path(__file__).absolute()), "--origin-probe"],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "HOME": "/nonexistent"}, start_new_session=True)
    try:
        raw, _ = process.communicate(json.dumps({"origin": origin, "challenge": challenge}, separators=(",", ":")).encode(), timeout=12)
        if process.returncode != 0 or len(raw) > 256:
            raise ValueError()
        value = json.loads(raw)
        if not isinstance(value, dict) or set(value) != {"status", "proof"} or type(value["status"]) is not int or not isinstance(value["proof"], str) or not HASH.fullmatch(value["proof"]):
            raise ValueError()
        return value
    finally:
        if process.poll() is None:
            process.kill()
            process.communicate()


def origin_proof(origin, keys, operation, identity, held, api, *, opener=None):
    """Challenge the enrolled origin; a stale proxy or old web cannot attest it."""
    challenge = secrets.token_hex(32)
    try:
        response = origin_response(origin, challenge, opener) if opener is not None else bounded_origin_response(origin, challenge)
        key = keys["current"]
        raw = base64.b64decode(key[7:], validate=True) if key.startswith("base64:") else key.encode("utf-8")
        derived = hmac.digest(raw, b"wayfindr-managed-origin-v1", "sha256")
        payload = json.dumps([1, challenge, operation if held else None, identity["version"], identity["commit"]], separators=(",", ":")).encode()
        expected = hmac.new(derived, payload, "sha256").hexdigest()
        if response["status"] != (503 if held else 200) or not hmac.compare_digest(expected, response["proof"]):
            raise ValueError()
    except Exception:
        raise api.Refusal("origin_verification_failed") from None


class Engine:
    """Fixed Docker commands. Private inspect output never enters the RPC journal."""

    def __init__(self, config, api, state_dir):
        self.base = sibling("update_protection").DockerEngine(config, api, state_dir)
        self.config, self.api, self.root = config, api, Path(state_dir)

    def __getattr__(self, name):
        return getattr(self.base, name)

    def full_plan(self, container, tag):
        return self.base.call(["exec", container, "php", "artisan", "wayfindr:update-plan", "--ref=" + tag, "--json"], "apply_unavailable", json=True)

    def layout(self, container):
        value = self.base.call(["inspect", "--format", '{"env":{{json .Config.Env}},"cmd":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"user":{{json .Config.User}},"workdir":{{json .Config.WorkingDir}},"mounts":{{json .Mounts}},"created":{{json .Created}},"operation":{{json (index .Config.Labels "io.wayfindr.managed-operation")}}}', container], "runtime_verification_failed", json=True)
        mounts = value["mounts"]
        if not isinstance(mounts, list):
            raise self.api.Refusal("runtime_verification_failed")
        value["mounts"] = sorted([{k: m.get(k) for k in ("Type", "Name", "Source", "Destination", "RW", "Driver")} for m in mounts], key=lambda m: m["Destination"])
        return value

    def origin(self, web):
        value = self.base.call(["exec", web, "php", "-r", 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo json_encode(["origin"=>config("app.url")],JSON_THROW_ON_ERROR);'], "origin_verification_failed", json=True)
        origin = value.get("origin")
        expected = self.base.runtime_environment(web).get("APP_URL")
        if not isinstance(origin, str) or origin != expected or len(origin) > 2048:
            raise self.api.Refusal("origin_verification_failed")
        if not valid_origin(origin):
            raise self.api.Refusal("origin_verification_failed")
        return origin.rstrip("/")

    def staged_compose(self, directory):
        return self.base.compose + ["-f", str(directory / "target.yml")]

    def oneoff(self, directory, operation, state, action):
        name = "wayfindr-updater-" + ("migration" if action == "migrate" else "apply-" + action) + "-" + operation
        if self.base.oneoff_active(name):
            raise self.api.Refusal("migration_ambiguous" if action == "migrate" else "recovery_required")
        args = ["run", "--no-deps", "--pull=never", "--entrypoint", "php", "--name", name, "-T"]
        if action != "migrate":
            args.append("--rm")
        code, raw = self.api.capture(self.staged_compose(directory) + args + ["web", *self.command(operation, state, action)], timeout=3600 if action == "migrate" else 90)
        if code != 0:
            raise self.api.Refusal("migration_failed" if action == "migrate" else "runtime_verification_failed")
        if action == "migrate":
            record = self.base.inspect(name)
            if record["image"] != state["artifacts"]["config_digest"] or record["state"].get("ExitCode") != 0 or self.base.oneoff_active(name):
                raise self.api.Refusal("migration_ambiguous")
        return self.api.strict_json(raw, "migration_ambiguous" if action == "migrate" else "runtime_verification_failed")

    def command(self, operation, state, action, source=False):
        target = state["source"] if source else state["target"]
        return ["artisan", "wayfindr:managed-apply", operation, "--action=" + action,
                "--plan-id=" + state["plan_id"], "--target-version=" + target["version"], "--commit=" + target["commit"],
                "--binding=" + state["source_context"]["capture_binding_sha256"], "--json"]

    def runtime_receipt(self, container, operation, state, source=False):
        return self.base.call(["exec", container, "php", *self.command(operation, state, "baseline" if source else "verify", source)], "runtime_verification_failed", timeout=90, json=True)

    def all_ids(self, service):
        raw = self.base.call(["ps", "-a", "-q", "--no-trunc", "--filter", "label=com.docker.compose.project=" + self.config.value["compose_project"], "--filter", "label=com.docker.compose.service=" + service, "--filter", "label=com.docker.compose.oneoff=False"])
        result = raw.splitlines() if raw else []
        if len(result) > 2 or len(set(result)) != len(result) or any(not HASH.fullmatch(item) for item in result):
            raise self.api.Refusal("recovery_required")
        return result

    def create(self, directory, service):
        code, _ = self.api.capture(self.staged_compose(directory) + ["create", "--no-deps", "--pull=never", "--force-recreate", service], timeout=120)
        if code != 0:
            raise self.api.Refusal("apply_timeout")

    def render(self, directory):
        code, raw = self.api.capture(self.staged_compose(directory) + ["config", "--format", "json"])
        if code != 0:
            raise self.api.Refusal("configuration_changed")
        return self.api.strict_json(raw, "configuration_changed")

    def target_layout(self, container, service, directory, state, *, processes=True):
        actual = self.layout(container)
        rendered = self.render(directory)["services"][service]
        image = self.base.call(["image", "inspect", "--format", '{"env":{{json .Config.Env}},"cmd":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"user":{{json .Config.User}},"workdir":{{json .Config.WorkingDir}}}', state["artifacts"]["config_digest"]], "runtime_verification_failed", json=True)
        expected = self.base.environment_map(image["env"])
        for key, value in rendered["environment"].items():
            if value is None:
                expected.pop(key, None)
            elif not isinstance(value, str):
                raise self.api.Refusal("runtime_verification_failed")
            else:
                expected[key] = value
        if (actual["operation"] != state["operation_id"] or self.base.environment_map(actual["env"]) != expected or actual["mounts"] != state["layouts"][service]["mounts"]
                or actual["cmd"] != rendered.get("command", image["cmd"]) or actual["entrypoint"] != rendered.get("entrypoint", image["entrypoint"])
                or actual["user"] != rendered.get("user", image["user"]) or actual["workdir"] != rendered.get("working_dir", image["workdir"])):
            raise self.api.Refusal("runtime_verification_failed")
        try:
            if datetime.fromisoformat(actual["created"].replace("Z", "+00:00")).timestamp() < state["started"]:
                raise ValueError()
        except (ValueError, TypeError, KeyError):
            raise self.api.Refusal("runtime_verification_failed") from None
        if not processes:
            return
        self.role_ready(container, service)

    def role_ready(self, container, service):
        # Inspect role argv and a live process; a healthy web alone is insufficient.
        processes = self.base.call(["top", container, "-eo", "args"], "runtime_verification_failed", timeout=5)
        role = {"web": "frankenphp", "queue": "queue:work redis", "backup-queue": "queue:work backups", "scheduler": "schedule:work", "reverb": "reverb:start"}[service]
        if role not in processes.lower():
            raise self.api.Refusal("runtime_verification_failed")

    def ensure_target_window(self, directory, operation, state):
        for service in SERVICES:
            for container in self.all_ids(service):
                self.base.commands_settled(container)
        if self.base.oneoff_active("wayfindr-updater-migration-" + operation) or self.base.oneoff_active("wayfindr-updater-fence-" + operation):
            raise self.api.Refusal("recovery_required")
        code, raw = self.api.capture(self.staged_compose(directory) + ["run", "--rm", "--no-deps", "--pull=never", "--entrypoint", "php", "--name", "wayfindr-updater-fence-" + operation, "-T", "web", "artisan", "wayfindr:upgrade-window", operation, "--action=enter", "--json"], timeout=30)
        if code != 0:
            raise self.api.Refusal("recovery_required")
        return self.api.strict_json(raw, "recovery_required")


class Applier:
    def __init__(self, config, journal, state_dir, api, engine=None, artifacts=None, protector=None, *, secure=True, proof=None):
        self.config, self.journal, self.root, self.api, self.secure = config, journal, Path(state_dir), api, secure
        self.engine = engine or Engine(config, api, state_dir)
        self.protector = protector or sibling("update_protection").Protector(config, journal, state_dir, api, engine=self.engine, secure=secure)
        self.artifacts = artifacts or sibling("update_artifacts").Artifacts(api)
        self.proof = proof or origin_proof

    def fail(self, reason):
        raise self.api.Refusal(reason)

    def checkpoint(self, operation, name, **facts):
        self.journal.apply_checkpoint(operation, name, facts)

    def persist(self, directory, state, stage):
        state["stage"] = stage
        self.api.atomic_write(directory / "state.json", state)

    def mkdir(self, path):
        if not path.parent.exists():
            path.parent.mkdir(mode=0o700)
            self.protector.fsync_directory(path.parent.parent)
        if self.secure:
            self.api.trusted(path.parent, directory=True)
        path.mkdir(mode=0o700)
        self.protector.fsync_directory(path.parent)

    def initial(self, directory, operation, *, pre_start_recovery=False):
        public = self.journal.status(operation)["operation"]
        context, keys = self.protector.baseline(operation)
        if pre_start_recovery and directory.exists():
            if self.secure:
                self.api.trusted(directory, directory=True)
            if set(path.name for path in directory.iterdir()) - {"keys.json"}:
                self.fail("recovery_required")
        else:
            self.mkdir(directory)
        state = {"schema": 1, "installation_id": self.config.installation_id, "operation_id": operation,
                 "plan_id": public["plan_id"], "source": public["source"], "target": public["target"], "stage": "download_intent",
                 "started": time.time(), "old_config": copy.deepcopy(self.config.value), "new_config": None, "overlay": None,
                 "source_context": context, "dependencies": sorted(self.engine.dependencies()),
                 "layouts": {service: self.engine.layout(container) for service, container in context["containers"].items()},
                 "origin": self.engine.origin(context["containers"]["web"]), "artifacts": None,
                 "target_ids": {}, "creation_intents": [], "migration_receipt_sha256": None, "runtime_receipt_sha256": None}
        self.api.atomic_write(directory / "keys.json", keys)
        self.persist(directory, state, "download_intent")
        return state

    def load(self, directory, operation):
        state = private_object(directory / "state.json", self.api, secure=self.secure)
        public = self.journal.status(operation)["operation"]
        if (set(state) != STATE_KEYS or type(state["schema"]) is not int or state["schema"] != 1 or state["installation_id"] != self.config.installation_id
                or state["operation_id"] != operation or state["plan_id"] != public["plan_id"] or state["source"] != public["source"]
                or state["target"] != public["target"] or state["stage"] not in STAGES or self.config.value not in (state["old_config"], state["new_config"])
                or not isinstance(state["target_ids"], dict) or not set(state["target_ids"]) <= set(SERVICES)
                or any(not isinstance(item, str) or not HASH.fullmatch(item) for item in state["target_ids"].values())
                or not isinstance(state["creation_intents"], list) or state["creation_intents"] != sorted(set(state["creation_intents"]))
                or not set(state["creation_intents"]) <= set(SERVICES) or type(state["started"]) not in {int, float}):
            self.fail("recovery_required")
        self.protector.check_keys(private_object(directory / "keys.json", self.api, 16384, self.secure))
        if not valid_origin(state["origin"]):
            self.fail("recovery_required")
        return state

    def pre_start_recovery(self, directory, operation):
        public = self.journal.status(operation)["operation"]
        apply, protection = public["apply"], public["protection"]
        allowed = {"operation_accepted", "prepare_started", "plan_reported", "operation_blocked", "apply_started",
                   "recovery_required", "apply_recovery_started", "apply_failed"}
        # Only initial admission can lack state. No later missing file, erased
        # artifact, truncated event window, or missing backup implies no effects.
        if (public["checkpoint"] != "apply_started" or public["mutation_started"]
                or any(apply[key] is not None for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256", "migration_receipt_sha256", "runtime_receipt_sha256"))
                or any(apply[key] for key in ("migration_started", "migration_verified", "services_verified", "origin_verified", "configuration_committed", "hold_owned"))
                or any(protection[key] is not None for key in ("archive_sha256", "manifest_sha256", "archive_bytes", "source_image_id", "local_attachment_disks", "external_attachment_disks", "offsite_uploaded", "offsite_verification"))
                or any(protection[key] for key in ("custody_verified", "services_recovered", "hold_owned"))
                or not any(event["code"] == "operation_accepted" for event in public["events"])
                or any(event["code"] not in allowed for event in public["events"])
                or (self.root / "protection" / operation).exists() or (self.root / "protection" / operation).is_symlink()
                or (directory / "state.json").exists() or (directory / "state.json").is_symlink()):
            self.fail("recovery_required")
        return self.initial(directory, operation, pre_start_recovery=True)

    def verify_staging(self, directory, operation, state):
        evidence = state["artifacts"]
        public = self.journal.status(operation)["operation"]["apply"]
        if not isinstance(evidence, dict) or evidence.get("target") != state["target"] or any(evidence.get(key) != public[key] for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256")):
            self.fail("artifact_verification_failed")
        architecture = {"x86_64": "amd64", "aarch64": "arm64", "arm64": "arm64"}.get(platform.machine().lower())
        if self.artifacts.verify(state["target"], evidence, architecture, directory / "artifacts") != evidence:
            self.fail("artifact_verification_failed")
        expected = copy.deepcopy(state["overlay"])
        for item in expected["services"].values():
            item["image"] = evidence["config_digest"]
        target_file = directory / "target.yml"
        if self.secure:
            self.api.trusted(target_file)
        if target_file.read_bytes() != self.api.encoded(expected):
            self.fail("configuration_changed")

    def download(self, directory, operation, state):
        self.config.verify_files()
        self.checkpoint(operation, "target_download_intent", phase="downloading")
        plan = self.engine.full_plan(state["source_context"]["containers"]["web"], state["target"]["tag"])
        reason, facts = self.api.plan_facts(plan, state["target"]["tag"], self.config)
        if reason != "execution_not_available" or facts != {key: state[key] for key in ("plan_id", "source", "target")}:
            self.fail("source_changed")
        architecture = {"x86_64": "amd64", "aarch64": "arm64", "arm64": "arm64"}.get(platform.machine().lower())
        if architecture is None:
            self.fail("platform_mismatch")
        self.mkdir(directory / "artifacts")
        state["artifacts"] = self.artifacts.prepare(state["target"], plan, architecture, directory / "artifacts")
        if state["artifacts"]["compose_sha256"] != state["old_config"]["compose_sha256"]:
            self.fail("configuration_changed")
        # Preserve the enrolled overlay's helper bind mounts exactly. Base
        # compose/.env/installer are unchanged; this MVP refuses compose changes.
        overlay_path = Path(self.config.value["install_dir"]) / "compose.updater.yml"
        old_overlay = overlay_path.read_bytes()
        # The enrolled initial YAML overlay is fixed; later promotions are JSON.
        if old_overlay.lstrip().startswith(b"{"):
            overlay = self.api.strict_json(old_overlay, "configuration_changed")
        else:
            # Installed helper code has no source tree. Use the fixed enrollment
            # shape and compare the actual rendered helper mounts below.
            overlay = {"services": {"web": {"environment": {
                "WAYFINDR_INSTALLATION_OWNERSHIP": "installer-managed", "WAYFINDR_INSTALLATION_ID": self.config.installation_id,
                "WAYFINDR_UPDATER_ENABLED": "true", "WAYFINDR_UPDATER_SOCKET": "/run/wayfindr-updater/updater.sock",
                "WAYFINDR_UPDATER_CREDENTIALS": "/run/wayfindr-updater-auth/credential.json"}, "volumes": [
                    {"type": "bind", "source": "/run/wayfindr-updater", "target": "/run/wayfindr-updater", "read_only": True, "bind": {"create_host_path": False}},
                    {"type": "bind", "source": "/etc/wayfindr-updater/credential.json", "target": "/run/wayfindr-updater-auth/credential.json", "read_only": True, "bind": {"create_host_path": False}}]}}}
        if set(overlay) != {"services"} or not isinstance(overlay["services"], dict) or not set(overlay["services"]) <= set(SERVICES) | {"storage-init"}:
            self.fail("configuration_changed")
        reference = "ghcr.io/adamgreenwell/wayfindr:" + state["target"]["version"] + "@" + state["target"]["image_digest"]
        for service in (*SERVICES, "storage-init"):
            item = overlay["services"].setdefault(service, {})
            item["image"] = reference
            if service != "storage-init":
                item.setdefault("environment", {}).update(WAYFINDR_IMAGE=reference, WAYFINDR_AUTO_MIGRATE="0")
                item.setdefault("labels", {})["io.wayfindr.managed-operation"] = operation
        state["overlay"] = overlay
        state["new_config"] = {**state["old_config"], "image_reference": reference, "overlay_sha256": digest(self.api.encoded(overlay))}
        staging = copy.deepcopy(overlay)
        for item in staging["services"].values():
            item["image"] = state["artifacts"]["config_digest"]
        self.api.atomic_write(directory / "target.yml", staging)
        self.persist(directory, state, "downloaded")
        self.checkpoint(operation, "target_verified", phase="protecting", **{key: state["artifacts"][key] for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256")})
        self.verify_staging(directory, operation, state)

    def receipt(self, value, operation, state, phases, source=False):
        identity = state["source"] if source else {key: state["target"][key] for key in ("version", "commit")}
        if (not isinstance(value, dict) or set(value) != RECEIPT_KEYS or type(value["schema"]) is not int or value["schema"] != 1
                or value["operation_id"] != operation or value["plan_id"] != state["plan_id"] or value["phase"] not in phases
                or value["target"] != {**identity, "profile": "image"} or value["binding_sha256"] != state["source_context"]["capture_binding_sha256"]
                or any(not isinstance(value[key], str) or not HASH.fullmatch(value[key]) for key in ("binding_sha256", "manifest_sha256", "history_sha256", "migrations_sha256", "release_state_sha256"))
                or any(value[key] is not True for key in ("guards_clear", "database_verified", "redis_verified", "hold_owned"))
                or type(value["pending_migrations"]) is not int or value["pending_migrations"] < 0
                or (phases != {"assessed"} and value["pending_migrations"] != 0)
                or (source and value["pending_migrations"] != 0)):
            self.fail("migration_ambiguous")
        if not source and (value["manifest_sha256"] != state["artifacts"]["manifest_sha256"] or value["history_sha256"] != state["artifacts"]["baked_history_sha256"]):
            self.fail("artifact_verification_failed")
        return value

    def migration(self, directory, operation, state, recovery):
        if recovery:
            if self.engine.oneoff_active("wayfindr-updater-migration-" + operation):
                self.fail("migration_ambiguous")
            value = self.engine.oneoff(directory, operation, state, "receipt")
        else:
            self.receipt(self.engine.oneoff(directory, operation, state, "assess"), operation, state, {"assessed"})
            # BOTH intent records precede launch. A gap is held rather than
            # inferring no schema writes from a missing receipt/container.
            self.persist(directory, state, "migration_intent")
            self.checkpoint(operation, "migration_intent", phase="applying", migration_started=True, hold_owned=True)
            value = self.engine.oneoff(directory, operation, state, "migrate")
        self.receipt(value, operation, state, {"complete"})
        verified = self.receipt(self.engine.oneoff(directory, operation, state, "verify"), operation, state, {"verified"})
        if any(value[key] != verified[key] for key in ("migrations_sha256", "release_state_sha256")):
            self.fail("migration_ambiguous")
        self.api.atomic_write(directory / "migration.json", value)
        state["migration_receipt_sha256"] = digest(self.api.encoded(value))
        self.persist(directory, state, "migrated")
        self.checkpoint(operation, "migrations_verified", phase="restarting", migration_verified=True, migration_receipt_sha256=state["migration_receipt_sha256"])

    def reconcile_services(self, directory, operation, state):
        self.persist(directory, state, "restart_intent")
        self.checkpoint(operation, "target_restart_intent", phase="restarting")
        self.protector.check_window(self.engine.ensure_target_window(directory, operation, state), operation, {key: state["target"][key] for key in ("version", "commit")}, True)
        for service in SERVICES:
            ids = self.engine.all_ids(service)
            candidates = []
            for container in ids:
                record = self.engine.inspect(container)
                if record["image"] == state["artifacts"]["config_digest"] and container != state["source_context"]["containers"][service]:
                    if service not in state["creation_intents"]:
                        self.fail("recovery_required")
                    candidates.append(container)
                elif container != state["source_context"]["containers"][service] or record["image"] != state["source_context"]["image"] or record["state"].get("Running") is not False:
                    self.fail("recovery_required")
            if len(candidates) > 1 or (candidates and len(ids) != 1):
                self.fail("recovery_required")
            if not candidates:
                # A prior timeout without an observed target is ambiguous.
                # Never repeat force-recreate merely because its client died.
                if service in state["creation_intents"]:
                    self.fail("recovery_required")
                state["creation_intents"] = sorted(set(state["creation_intents"]) | {service})
                self.persist(directory, state, "restart_intent")
                self.engine.create(directory, service)
                ids = self.engine.all_ids(service)
                if len(ids) != 1 or ids[0] == state["source_context"]["containers"][service]:
                    self.fail("recovery_required")
                candidates = ids
            container = candidates[0]
            if service in state["target_ids"] and state["target_ids"][service] != container:
                self.fail("recovery_required")
            self.engine.commands_settled(container)
            record = self.engine.inspect(container)
            self.check_target(record, service, state, running=None)
            self.engine.target_layout(container, service, directory, state, processes=False)
            state["target_ids"][service] = container
            self.persist(directory, state, "restart_intent")
            if record["state"]["Running"] is False:
                self.engine.start({service: container})
        self.checkpoint(operation, "target_services_started", phase="verifying")

    def check_target(self, record, service, state, running=True):
        status = record.get("state", {})
        if (record.get("id") == state["source_context"]["containers"][service] or not isinstance(record.get("id"), str) or not HASH.fullmatch(record["id"])
                or record.get("image") != state["artifacts"]["config_digest"] or record.get("project") != self.config.value["compose_project"]
                or record.get("service") != service or str(record.get("oneoff")).lower() != "false" or type(status.get("Running")) is not bool
                or (running is not None and status["Running"] is not running) or any(status.get(key) is not False for key in ("Paused", "Restarting", "Dead", "OOMKilled"))
                or status.get("Error") or record.get("restarts") != 0 or (not status["Running"] and status.get("ExitCode") != 0)):
            self.fail("runtime_verification_failed")

    def verify_runtime(self, directory, operation, state, source=False):
        ids = state["source_context"]["containers"] if source else state["target_ids"]
        if set(ids) != set(SERVICES) or self.engine.service_ids() != ids or sorted(self.engine.dependencies()) != state["dependencies"]:
            self.fail("runtime_verification_failed")
        self.engine.writers(set(ids.values()) | set(state["dependencies"]))
        self.engine.settled(ids, operation)
        receipts = {}
        for service, container in ids.items():
            if source:
                self.protector.check_records(state["source_context"], True)
                if self.engine.layout(container) != state["layouts"][service]:
                    self.fail("runtime_verification_failed")
            else:
                self.check_target(self.engine.inspect(container), service, state)
                self.engine.target_layout(container, service, directory, state, processes=False)
            receipts[service] = self.receipt(self.engine.runtime_receipt(container, operation, state, source), operation, state, {"assessed"} if source else {"verified"}, source)
        if any(receipts[service] != receipts["web"] for service in SERVICES):
            self.fail("runtime_verification_failed")
        deadline = time.monotonic() + 90
        while True:
            try:
                if self.engine.web_status(ids["web"]) != 503:
                    self.fail("runtime_verification_failed")
                self.engine.reverb_ready(ids["reverb"])
                for service, container in ids.items():
                    self.engine.role_ready(container, service)
                break
            except Exception:
                if time.monotonic() >= deadline:
                    self.fail("runtime_verification_failed")
                time.sleep(1)
        self.verify_origin(directory, operation, state, True, source)
        value = {"schema": 1, "operation_id": operation, "plan_id": state["plan_id"], "containers": ids, "receipt": receipts["web"]}
        self.api.atomic_write(directory / "runtime.json", value)
        state["runtime_receipt_sha256"] = digest(self.api.encoded(value))
        if not source:
            self.persist(directory, state, "runtime_verified")
            self.checkpoint(operation, "runtime_verified", phase="verifying", services_verified=True, origin_verified=True, runtime_receipt_sha256=state["runtime_receipt_sha256"])

    def verify_origin(self, directory, operation, state, held, source=False):
        keys = private_object(directory / "keys.json", self.api, 16384, self.secure)
        identity = state["source"] if source else {key: state["target"][key] for key in ("version", "commit")}
        self.proof(state["origin"], keys, operation, identity, held, self.api)

    def promote(self, directory, operation, state):
        self.persist(directory, state, "configuration_commit_intent")
        self.checkpoint(operation, "configuration_commit_intent", phase="verifying")
        # Accept only the exact reviewed pair during interruption reconciliation.
        current = self.api.read_object(self.api.CONFIG, 16384, "configuration_changed")
        install = Path(state["old_config"]["install_dir"])
        overlay = install / "compose.updater.yml"
        if self.secure:
            self.api.trusted(self.api.CONFIG)
            self.api.trusted(overlay)
        for filename, key in ((".env", "env_sha256"), ("compose.yml", "compose_sha256"), ("install.sh", "installer_sha256")):
            path = install / filename
            if self.secure:
                self.api.trusted(path)
            if digest(path.read_bytes()) != state["old_config"][key]:
                self.fail("configuration_changed")
        if current not in (state["old_config"], state["new_config"]) or digest(overlay.read_bytes()) not in {state["old_config"]["overlay_sha256"], state["new_config"]["overlay_sha256"]}:
            self.fail("configuration_changed")
        self.api.atomic_write(overlay, state["overlay"])
        self.api.atomic_write(self.api.CONFIG, state["new_config"])
        self.config.value = copy.deepcopy(state["new_config"])
        self.config.verify_files()
        self.persist(directory, state, "configuration_committed")
        self.checkpoint(operation, "configuration_committed", phase="verifying", configuration_committed=True)

    def finish_target(self, directory, operation, state):
        self.engine.settled(state["target_ids"], operation)
        self.persist(directory, state, "release_intent")
        self.checkpoint(operation, "apply_release_intent", phase="verifying")
        identity = {key: state["target"][key] for key in ("version", "commit")}
        self.protector.check_window(self.engine.window(state["target_ids"]["web"], operation, "release"), operation, identity, False)
        self.checkpoint(operation, "apply_release_intent", phase="verifying", hold_owned=False)
        self.engine.settled(state["target_ids"], operation)
        if self.engine.web_status(state["target_ids"]["web"]) != 200:
            self.fail("runtime_verification_failed")
        if self.engine.service_ids() != state["target_ids"] or sorted(self.engine.dependencies()) != state["dependencies"]:
            self.fail("runtime_verification_failed")
        for service, container in state["target_ids"].items():
            self.check_target(self.engine.inspect(container), service, state)
            self.engine.target_layout(container, service, directory, state)
        self.engine.writers(set(state["target_ids"].values()) | set(state["dependencies"]))
        self.engine.reverb_ready(state["target_ids"]["reverb"])
        self.verify_origin(directory, operation, state, False)
        self.persist(directory, state, "complete")
        self.checkpoint(operation, "serving_verified", phase="verifying", hold_owned=False)
        self.journal.apply_finish(operation, None, "succeeded")

    def fallback(self, directory, operation, state, reason):
        self.checkpoint(operation, "apply_started", phase="protecting")
        protection = self.root / "protection" / operation
        if (protection / "state.json").exists():
            context = self.protector.load_context(protection, operation)
            state["source_context"] = context
        else:
            self.mkdir(protection)
            keys = private_object(directory / "keys.json", self.api, 16384, self.secure)
            self.api.atomic_write(protection / "keys.json", keys)
            self.api.atomic_write(protection / "image.yml", {"services": {"web": {"image": state["source_context"]["image"]}}})
            self.protector.persist(protection, state["source_context"], "fence_intent")
        try:
            self.protector.recover(protection, operation, state["source_context"], before_release=lambda: self.verify_runtime(directory, operation, state, source=True))
            self.verify_origin(directory, operation, state, False, source=True)
            self.persist(directory, state, "fallback")
            self.checkpoint(operation, "previous_serving_verified", phase="protecting", services_verified=True, origin_verified=True,
                            runtime_receipt_sha256=state["runtime_receipt_sha256"], hold_owned=False)
            self.journal.apply_finish(operation, reason, "failed_safe")
        except Exception:
            # Source serving was released only after baseline verification.
            # A failed final origin proof must regain the original fence, too.
            try:
                context = state["source_context"]
                self.protector.check_records(context, None)
                self.engine.settled(context["containers"], operation)
                if self.engine.backup_active(operation):
                    self.fail("recovery_required")
                value = self.engine.ensure_window(context["containers"], operation, context["image"])
                self.protector.check_window(value, operation, context["source"], True)
                self.checkpoint(operation, "apply_started", phase="protecting", hold_owned=True)
            except Exception:
                pass
            raise

    def __call__(self, operation, recovery=False):
        directory = self.root / "apply" / operation
        state = None
        fallback_attempted = False
        try:
            if recovery:
                state = self.load(directory, operation) if (directory / "state.json").exists() or (directory / "state.json").is_symlink() else self.pre_start_recovery(directory, operation)
            else:
                state = self.initial(directory, operation)
            public = self.journal.status(operation)["operation"]
            ambiguous = public["mutation_started"] or state["stage"] in {"migration_intent", "migrated", "restart_intent", "runtime_verified", *PROMOTION_STAGES}
            if recovery and not ambiguous:
                fallback_attempted = True
                self.fallback(directory, operation, state, public["apply"]["error"] or "apply_failed")
                return
            if not recovery:
                self.download(directory, operation, state)
                self.persist(directory, state, "protect_intent")
                context = self.protector.capture(operation, retain_hold=True)
                frozen = state["source_context"]
                if any(context[key] != frozen[key] for key in ("containers", "image", "source", "key_fingerprints", "capture_binding_sha256", "environment_binding")):
                    self.fail("source_changed")
                state["source_context"] = context
                self.persist(directory, state, "protected")
                if sorted(self.engine.dependencies()) != state["dependencies"]:
                    self.fail("source_changed")
                self.checkpoint(operation, "data_protected", phase="applying", hold_owned=True)
            elif not public["mutation_started"]:
                # Private intent preceded the public write; retain ambiguity.
                self.checkpoint(operation, "migration_intent", phase="applying", migration_started=True, hold_owned=True)
            if recovery:
                self.verify_staging(directory, operation, state)
                if state["stage"] in PROMOTION_STAGES:
                    # Complete the exact durable promotion pair before changing
                    # its stage. A second crash must still load the helper when
                    # overlay/config were split across the first interruption.
                    self.promote(directory, operation, state)
                self.protector.check_window(self.engine.ensure_target_window(directory, operation, state), operation, {key: state["target"][key] for key in ("version", "commit")}, True)
            self.migration(directory, operation, state, recovery)
            self.reconcile_services(directory, operation, state)
            self.verify_runtime(directory, operation, state)
            self.promote(directory, operation, state)
            self.finish_target(directory, operation, state)
        except Exception as failure:
            reason = failure.reason if isinstance(failure, self.api.Refusal) else "apply_failed"
            public = self.journal.status(operation)["operation"]
            private_intent = state is not None and state["stage"] in {"migration_intent", "migrated", "restart_intent", "runtime_verified", *PROMOTION_STAGES}
            if state is not None and not public["mutation_started"] and not private_intent and not fallback_attempted:
                try:
                    self.fallback(directory, operation, state, reason)
                    return
                except Exception:
                    pass
            elif state is not None and (public["mutation_started"] or private_intent):
                try:
                    self.verify_staging(directory, operation, state)
                    self.protector.check_window(self.engine.ensure_target_window(directory, operation, state), operation,
                                               {key: state["target"][key] for key in ("version", "commit")}, True)
                    self.checkpoint(operation, "apply_release_intent", phase="verifying", hold_owned=True)
                except Exception:
                    pass
            # No unobserved command result or missing file qualifies for rollback.
            self.journal.apply_finish(operation, reason, "recovery_required")


if __name__ == "__main__":
    try:
        if sys.argv[1:] != ["--origin-probe"]:
            raise ValueError()
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096:
            raise ValueError()
        request = json.loads(raw)
        if not isinstance(request, dict) or set(request) != {"origin", "challenge"} or not valid_origin(request["origin"]) or not isinstance(request["challenge"], str) or not HASH.fullmatch(request["challenge"]):
            raise ValueError()
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        sys.stdout.buffer.write(json.dumps(origin_response(request["origin"], request["challenge"], opener), separators=(",", ":")).encode())
    except Exception:
        sys.exit(1)
