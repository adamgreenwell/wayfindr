#!/usr/bin/env python3
"""Synthetic release/registry/Docker fixtures; no network or real containers."""

from copy import deepcopy
import hashlib
import importlib.util
import io
import json
import os
import sys
from pathlib import Path
import tarfile
import tempfile
import time
import unittest
from unittest.mock import patch
import urllib.request


MODULE = Path(__file__).parent / "self-host/update_artifacts.py"
spec = importlib.util.spec_from_file_location("update_artifacts", MODULE)
artifacts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(artifacts)


def encoded(value):
    return (json.dumps(value, indent=4) + "\n").encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def digest(raw):
    return "sha256:" + sha(raw)


def archive(name, raw, kind=tarfile.REGTYPE, duplicate=False):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode="w", format=tarfile.USTAR_FORMAT) as handle:
        member = tarfile.TarInfo(name)
        member.type = kind
        member.linkname = "release.json" if kind != tarfile.REGTYPE else ""
        member.size = len(raw) if kind == tarfile.REGTYPE else 0
        handle.addfile(member, io.BytesIO(raw) if kind == tarfile.REGTYPE else None)
        if duplicate:
            handle.addfile(member, io.BytesIO(raw) if kind == tarfile.REGTYPE else None)
    return output.getvalue()


class Refusal(Exception):
    pass


class Fixture:
    def __init__(self, architecture="amd64", **options):
        self.architecture = architecture
        self.engine = options.get("engine", "classic")
        self.legacy_config = options.get("legacy_config", False)
        self.urls = {}
        self.fetches = []
        self.commands = []
        self.commit = "a" * 40
        self.tag = "v1.2.0"
        self.manifest = {"schema": 1, "version": "1.2.0", "commit": self.commit,
                         "requires_operator_action": False, "minimum_upgrade_from": "0.1.0-alpha.1", "actions": []}
        self.manifest_raw = encoded(self.manifest)
        self.history = {"schema": 1, "releases": [
            {**self.manifest, "version": "0.1.0", "commit": ""},
            {**self.manifest, "commit": ""},
        ]}
        self.history_raw = encoded(self.history)
        self.baked = {"schema": 1, "releases": [self.history["releases"][0], self.manifest]}
        self.config = {
            "User": options.get("user", "wayfindr"),
            "Entrypoint": ["wayfindr-entrypoint"],
            "Cmd": ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"],
            "WorkingDir": "/app/apps/server", "ExposedPorts": {"80/tcp": {}},
            "Healthcheck": {"Test": ["CMD", "wayfindr-healthcheck"], "Interval": 5000000000},
            "Env": ["PATH=/usr/local/bin:/usr/bin:/bin", "WAYFINDR_VERSION=" + self.tag, "WAYFINDR_COMMIT=" + self.commit],
            "Labels": {"org.opencontainers.image.source": "https://github.com/adamgreenwell/wayfindr",
                       "org.opencontainers.image.revision": self.commit,
                       "org.opencontainers.image.version": options.get("label_version", "1.2.0")},
        }
        self.config.update(options.get("extra_config", {}))
        if options.get("volumes"):
            self.config["Volumes"] = options["volumes"]
        if options.get("duplicate_env"):
            self.config["Env"].append("WAYFINDR_VERSION=" + self.tag)
        image_config = {"architecture": options.get("config_architecture", architecture), "os": "linux", "config": self.config,
                        "rootfs": {"type": "layers", "diff_ids": ["sha256:" + "1" * 64]}}
        if options.get("config_variant") is not None:
            image_config["variant"] = options["config_variant"]
        self.rootfs = {"Type": image_config["rootfs"]["type"], "Layers": image_config["rootfs"]["diff_ids"]}
        self.variant = image_config.get("variant", "")
        self.config_raw = encoded(image_config)
        self.config_digest = digest(self.config_raw)
        docker_types = options.get("docker_types", False)
        manifest_type = "application/vnd.docker.distribution.manifest.v2+json" if docker_types else "application/vnd.oci.image.manifest.v1+json"
        index_type = "application/vnd.docker.distribution.manifest.list.v2+json" if docker_types else "application/vnd.oci.image.index.v1+json"
        config_type = "application/vnd.docker.container.image.v1+json" if docker_types else "application/vnd.oci.image.config.v1+json"
        child = {"schemaVersion": 2, "mediaType": manifest_type,
                 "config": {"mediaType": config_type, "digest": self.config_digest, "size": len(self.config_raw) + options.get("config_size_offset", 0)},
                 "layers": [{"mediaType": "application/vnd.oci.image.layer.v1.tar+gzip", "digest": "sha256:" + "2" * 64, "size": 42}]}
        if options.get("foreign_layer"):
            child["layers"][0]["urls"] = ["https://other.example/credentials"]
        if options.get("artifact"):
            child["artifactType"] = "application/example"
        self.child_raw = encoded(child)
        self.child_digest = digest(self.child_raw)
        index = {"schemaVersion": 2, "mediaType": index_type, "manifests": []}
        for arch in ("amd64", "arm64"):
            descriptor = {"mediaType": manifest_type, "digest": self.child_digest if arch == architecture else "sha256:" + "3" * 64,
                          "size": len(self.child_raw) + options.get("child_size_offset", 0), "platform": {"os": "linux", "architecture": arch}}
            if arch == "arm64" and options.get("variant"):
                descriptor["platform"]["variant"] = options["variant"]
            if not (options.get("missing_platform") and arch == architecture):
                index["manifests"].append(descriptor)
        if options.get("duplicate_platform"):
            index["manifests"].append(deepcopy(index["manifests"][0]))
        if options.get("attestation"):
            index["manifests"].append({"mediaType": manifest_type, "digest": "sha256:" + "4" * 64, "size": 23,
                                       "platform": {"os": "unknown", "architecture": "unknown"},
                                       "annotations": {"vnd.docker.reference.type": "attestation-manifest", "vnd.docker.reference.digest": self.child_digest}})
        self.selected_descriptor = next(deepcopy(item) for item in index["manifests"] if item["digest"] == self.child_digest) if any(item["digest"] == self.child_digest for item in index["manifests"]) else None
        self.index_raw = encoded(index)
        self.index_digest = digest(self.index_raw)
        self.target = {"tag": self.tag, "version": "1.2.0", "commit": self.commit, "image_digest": self.index_digest}
        self.digest_raw = (self.index_digest + "\n").encode()
        self.compose_raw = b"name: wayfindr-self-hosting\nservices: {}\n"
        self.plan = {
            "schema": 1,
            "target": {**self.target, "image_reference": artifacts.IMAGE + ":1.2.0@" + self.index_digest, "platform": "linux/" + architecture},
            "declarations": {"manifest": self.manifest, "history": self.baked["releases"]},
            "provenance": {"repository": "adamgreenwell/wayfindr", "tag": self.tag, "commit": self.commit,
                           "release_id": 123, "release_api_url": artifacts.API + "/releases/tags/" + self.tag,
                           "release_url": artifacts.RELEASES + "/tag/" + self.tag,
                           "manifest_url": artifacts.RELEASES + "/download/" + self.tag + "/release-manifest.json",
                           "manifest_sha256": sha(self.manifest_raw),
                           "digest_url": artifacts.RELEASES + "/download/" + self.tag + "/release-image-digest.txt",
                           "digest_asset_sha256": sha(self.digest_raw), "history_url": artifacts.RAW + "/" + self.commit + "/releases/history.json",
                           "history_sha256": sha(self.history_raw), "history_floor": "0.1.0-alpha.1", "history_contract_from": "0.1.0",
                           "history_complete": True, "history_coverage_basis": "validated_committed_release_contract"},
        }
        self.release = {"id": 123, "draft": False, "prerelease": False, "tag_name": self.tag, "html_url": self.plan["provenance"]["release_url"],
                        "assets": [{"name": filename, "state": "uploaded", "browser_download_url": self.plan["provenance"][url_key], "digest": "sha256:" + self.plan["provenance"][hash_key]}
                                   for filename, url_key, hash_key in (("release-manifest.json", "manifest_url", "manifest_sha256"), ("release-image-digest.txt", "digest_url", "digest_asset_sha256"))]}
        self.urls[self.plan["provenance"]["release_api_url"]] = encoded(self.release)
        self.urls[artifacts.API + "/git/ref/tags/" + self.tag] = encoded({"ref": "refs/tags/" + self.tag, "object": {"type": "commit", "sha": self.commit}})
        self.urls[self.plan["provenance"]["manifest_url"]] = self.manifest_raw
        self.urls[self.plan["provenance"]["digest_url"]] = self.digest_raw
        self.urls[self.plan["provenance"]["history_url"]] = self.history_raw
        self.urls[artifacts.RAW + "/" + self.commit + "/docker/self-hosting/compose.yml"] = self.compose_raw
        self.urls[artifacts.TOKEN] = encoded({"token": "public-anonymous-token"})
        self.urls[artifacts.REGISTRY + "/manifests/" + self.index_digest] = self.index_raw
        self.urls[artifacts.REGISTRY + "/manifests/" + self.child_digest] = self.child_raw
        self.urls[artifacts.REGISTRY + "/blobs/" + self.config_digest] = self.config_raw
        self.copies = {"version": (self.tag + "\n").encode(), "commit": (self.commit + "\n").encode(),
                       "release.json": self.manifest_raw, "release-history.json": encoded(self.baked)}
        self.id = "b" * 64
        self.actual_id = {"classic": self.config_digest, "containerd-index": self.index_digest,
                          "containerd-platform": self.child_digest}[self.engine]
        self.descriptor = None if self.engine == "classic" else (
            {"mediaType": index_type, "digest": self.index_digest, "size": len(self.index_raw)}
            if self.engine == "containerd-index" else next(deepcopy(item) for item in index["manifests"]
                                                         if item["digest"] == self.child_digest))
        self.container_descriptor = None if self.engine == "classic" else deepcopy(self.selected_descriptor)
        self.state = "created"
        self.copy_kind = tarfile.REGTYPE
        self.copy_duplicate = False
        self.failure = None

    def fetch(self, url, *, headers, maximum, deadline):
        self.fetches.append((url, headers, maximum, deadline))
        if self.failure == "fetch":
            raise RuntimeError("secret=http-password/customer-data")
        return self.urls[url]

    def trusted(self, path, directory=False):
        if path.is_symlink():
            raise Refusal("unsafe")

    Refusal = Refusal

    def capture(self, command, *, timeout):
        assert command[:len(artifacts.DOCKER)] == artifacts.DOCKER
        args = command[len(artifacts.DOCKER):]
        self.commands.append(args)
        assert 0 < timeout <= artifacts.TIMEOUT
        if self.failure == args[0]:
            return 1, b"secret=private-data"
        if args[0] == "pull":
            assert args == ["pull", "--platform=linux/" + self.architecture, artifacts.IMAGE + "@" + self.index_digest]
            return 0, b"pulled"
        if args[:2] == ["image", "inspect"]:
            assert args == ["image", "inspect", "--format", "{{json .}}", artifacts.IMAGE + "@" + self.index_digest]
            config = deepcopy(self.config)
            if self.legacy_config:
                # Exact v24/v27/early-v28 container.Config serializer defaults,
                # independently declared here rather than read from the verifier.
                for key, default in {"Hostname": "", "Domainname": "", "AttachStdin": False,
                                     "AttachStdout": False, "AttachStderr": False, "Tty": False,
                                     "OpenStdin": False, "StdinOnce": False, "Image": "",
                                     "Volumes": None, "OnBuild": None, "WorkingDir": ""}.items():
                    config.setdefault(key, default)
            return 0, encoded({"Id": self.actual_id, "Os": "linux", "Architecture": self.architecture, "Variant": self.variant,
                               "Config": config, "RootFS": self.rootfs, "Descriptor": self.descriptor,
                               "RepoDigests": [artifacts.IMAGE + "@" + self.index_digest]})
        if args[0] == "create":
            assert args[-1] == artifacts.IMAGE + ":1.2.0@" + self.index_digest
            assert "--platform=linux/" + self.architecture in args
            assert "--network=none" in args and "--pull=never" in args
            assert args[args.index("--entrypoint") + 1] == "/bin/true"
            return 0, (self.id + "\n").encode()
        if args[0] == "inspect":
            mounts = [{"Type": "volume", "Name": "c" * 64, "Destination": name} for name in self.config.get("Volumes", {})]
            assert args[args.index("--format") + 1] == "{{json .}}"
            return 0, encoded({"Id": self.id, "Image": self.actual_id,
                               "State": {"Status": self.state, "Running": self.state != "created", "Pid": 0,
                                         "StartedAt": "0001-01-01T00:00:00Z"},
                               "Mounts": mounts, "Config": {"Entrypoint": ["/bin/true"], "Image": artifacts.IMAGE + ":1.2.0@" + self.index_digest},
                               "HostConfig": {"NetworkMode": "none"},
                               "ImageManifestDescriptor": self.container_descriptor})
        if args[0] == "cp":
            name = args[1].split("/")[-1]
            assert args[1].startswith(self.id + ":/etc/wayfindr/") and args[2] == "-"
            return 0, archive(name, self.copies[name], self.copy_kind, self.copy_duplicate)
        if args[0] == "rm":
            assert args == ["rm", "--volumes", self.id]
            return 0, (self.id + "\n").encode()
        raise AssertionError("Unexpected Docker operation: " + str(args))


class ArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        os.chmod(self.directory, 0o700)

    def tearDown(self):
        self.temp.cleanup()

    def prepare(self, fixture):
        return artifacts.Artifacts(fixture, fixture.fetch).prepare(fixture.target, fixture.plan, fixture.architecture, self.directory)

    def refuses(self, fixture):
        with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            self.prepare(fixture)
        self.assertFalse((self.directory / "artifacts.json").exists())

    def test_full_index_platform_config_bake_chain_and_private_receipt(self):
        fixture = Fixture(attestation=True)
        result = self.prepare(fixture)
        self.assertNotEqual(result["index_digest"], result["config_digest"])
        self.assertNotEqual(result["platform_manifest_digest"], result["config_digest"])
        self.assertEqual(result, {"schema": 2, "target": fixture.target, "index_digest": fixture.index_digest,
                                  "platform_manifest_digest": fixture.child_digest, "config_digest": fixture.config_digest,
                                  "platform_manifest_descriptor": fixture.selected_descriptor,
                                  "local_image_id": fixture.config_digest, "local_image_descriptor": None,
                                  "execution_reference": artifacts.IMAGE + ":1.2.0@" + fixture.index_digest,
                                  "execution_platform": "linux/amd64",
                                  "manifest_sha256": sha(fixture.manifest_raw), "history_sha256": sha(fixture.history_raw),
                                  "baked_history_sha256": sha(fixture.copies["release-history.json"]),
                                  "digest_asset_sha256": sha(fixture.digest_raw), "compose_sha256": sha(fixture.compose_raw)})
        self.assertEqual(json.loads((self.directory / "artifacts.json").read_bytes()), result)
        for path in self.directory.iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        self.assertNotIn("public-anonymous-token", str(result))
        self.assertFalse(any(command[0] in ("start", "run", "exec", "stop", "kill", "compose") for command in fixture.commands))
        self.assertTrue(all("Authorization" not in headers for url, headers, _, _ in fixture.fetches if not url.startswith(artifacts.REGISTRY)))

    def test_arm64_v8_docker_media_types_and_base_anonymous_volumes(self):
        fixture = Fixture("arm64", variant="v8", docker_types=True, volumes={"/data": {}, "/config": {}})
        self.assertEqual(self.prepare(fixture)["config_digest"], fixture.config_digest)

    def test_recovery_verify_uses_only_retained_bytes_and_one_read_only_inspect(self):
        fixture = Fixture("arm64", variant="v8", attestation=True, volumes={"/data": {}, "/config": {}})
        evidence = self.prepare(fixture)
        before = {path.name: (path.read_bytes(), path.stat().st_mode, path.stat().st_mtime_ns) for path in self.directory.iterdir()}
        fixture.commands.clear()
        fixture.fetches.clear()
        fixture.failure = "fetch"
        verifier = artifacts.Artifacts(fixture, fixture.fetch)
        self.assertIs(verifier.verify(fixture.target, evidence, fixture.architecture, self.directory), evidence)
        self.assertEqual(fixture.fetches, [])
        self.assertEqual(len(fixture.commands), 1)
        self.assertEqual(fixture.commands[0][:2], ["image", "inspect"])
        self.assertEqual(fixture.commands[0][-1], artifacts.IMAGE + "@" + fixture.index_digest)
        after = {path.name: (path.read_bytes(), path.stat().st_mode, path.stat().st_mtime_ns) for path in self.directory.iterdir()}
        self.assertEqual(before, after)

    def test_recovery_verifies_annotated_tag_from_retained_files(self):
        fixture = Fixture()
        fixture.urls[artifacts.API + "/git/ref/tags/" + fixture.tag] = encoded({"ref": "refs/tags/" + fixture.tag, "object": {"type": "tag", "sha": "d" * 40}})
        fixture.urls[artifacts.API + "/git/tags/" + "d" * 40] = encoded({"object": {"type": "commit", "sha": fixture.commit}})
        evidence = self.prepare(fixture)
        fixture.fetches.clear()
        artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
        self.assertFalse(fixture.fetches)
        (self.directory / "tag-0.json").write_bytes(encoded({"object": {"type": "commit", "sha": "e" * 40}}))
        with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)

    def test_recovery_checks_every_retained_digest_and_baked_identity_file(self):
        names = ("artifacts.json", "release-manifest.json", "release-image-digest.txt", "release-history.json", "compose.yml",
                 "image-index.json", "image-platform-manifest.json", "image-config.json", "image-version", "image-commit",
                 "image-release.json", "image-release-history.json")
        for name in names:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                evidence = self.prepare(fixture)
                path = self.directory / name
                path.write_bytes(path.read_bytes() + b" ")
                fixture.commands.clear()
                fixture.fetches.clear()
                with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
                self.assertFalse(fixture.commands)
                self.assertFalse(fixture.fetches)

    def test_recovery_receipt_requires_exact_keys_types_target_and_digests(self):
        fixture = Fixture()
        evidence = self.prepare(fixture)
        mutations = ({**evidence, "schema": True}, {**evidence, "extra": "secret"},
                     {**evidence, "target": {**fixture.target, "commit": "e" * 40}},
                     {**evidence, "config_digest": fixture.index_digest},
                     {**evidence, "manifest_sha256": "A" * 64},
                     {key: value for key, value in evidence.items() if key != "baked_history_sha256"})
        fixture.commands.clear()
        fixture.fetches.clear()
        for changed in mutations:
            with self.subTest(changed=changed), self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, changed, fixture.architecture, self.directory)
        self.assertFalse(fixture.commands)
        self.assertFalse(fixture.fetches)

    def test_recovery_refuses_unsafe_missing_or_unbounded_retained_files(self):
        for mutation in ("missing", "symlink", "permissions", "directory", "fifo", "oversize", "empty"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                evidence = self.prepare(fixture)
                path = self.directory / "release-manifest.json"
                if mutation == "permissions":
                    os.chmod(path, 0o644)
                elif mutation in ("oversize", "empty"):
                    path.write_bytes(b"x" * (artifacts.MAXIMUM + 1) if mutation == "oversize" else b"")
                else:
                    path.unlink()
                    if mutation == "symlink":
                        path.symlink_to(self.directory / "image-release.json")
                    elif mutation == "directory":
                        path.mkdir(mode=0o700)
                    elif mutation == "fifo":
                        os.mkfifo(path, 0o600)
                fixture.commands.clear()
                with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
                self.assertFalse(fixture.commands)

    def test_recovery_reconstructs_baked_history_independently_even_with_matching_hash(self):
        fixture = Fixture()
        evidence = self.prepare(fixture)
        # A receipt hash alone cannot turn the committed source into image history.
        (self.directory / "image-release-history.json").write_bytes(fixture.history_raw)
        changed = {**evidence, "baked_history_sha256": sha(fixture.history_raw)}
        (self.directory / "artifacts.json").write_bytes((json.dumps(changed, sort_keys=True, separators=(",", ":")) + "\n").encode())
        fixture.commands.clear()
        with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, changed, fixture.architecture, self.directory)
        self.assertFalse(fixture.commands)

    def test_recovery_rejects_local_image_identity_drift_without_pull_or_probe(self):
        for mutation in ("id", "architecture", "digests", "revision", "env", "unavailable"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                evidence = self.prepare(fixture)
                original = fixture.capture
                def capture(command, *, timeout):
                    code, raw = original(command, timeout=timeout)
                    value = json.loads(raw)
                    if mutation == "unavailable":
                        return 1, b"secret=customer-data"
                    if mutation == "id":
                        value["Id"] = fixture.index_digest
                    elif mutation == "architecture":
                        value["Architecture"] = "arm64"
                    elif mutation == "digests":
                        value["RepoDigests"] = []
                    elif mutation == "revision":
                        value["Config"]["Labels"]["org.opencontainers.image.revision"] = "e" * 40
                    else:
                        value["Config"]["Env"] = ["WAYFINDR_VERSION=v1.2.1", "WAYFINDR_COMMIT=" + fixture.commit]
                    return code, encoded(value)
                fixture.commands.clear()
                fixture.capture = capture
                with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
                self.assertEqual(len(fixture.commands), 1)
                self.assertEqual(fixture.commands[0][:2], ["image", "inspect"])

    def test_recovery_deadline_and_retained_release_publication_identity(self):
        fixture = Fixture()
        evidence = self.prepare(fixture)
        fixture.commands.clear()
        with patch.object(artifacts.time, "monotonic", side_effect=[0, artifacts.TIMEOUT + 1]), self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
        self.assertFalse(fixture.commands)
        (self.directory / "release-api.json").write_bytes(encoded({**fixture.release, "draft": True}))
        with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
        self.assertFalse(fixture.commands)

    def test_annotated_tag_resolution(self):
        fixture = Fixture()
        fixture.urls[artifacts.API + "/git/ref/tags/" + fixture.tag] = encoded({"ref": "refs/tags/" + fixture.tag, "object": {"type": "tag", "sha": "d" * 40}})
        fixture.urls[artifacts.API + "/git/tags/" + "d" * 40] = encoded({"object": {"type": "commit", "sha": fixture.commit}})
        self.prepare(fixture)
        self.assertTrue((self.directory / "tag-0.json").is_file())

    def test_rejects_unpublished_ambiguous_assets_and_moved_tag(self):
        for mutation in ("draft", "prerelease", "duplicate", "asset_digest", "asset_origin", "tag", "id", "boolean_id"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                if mutation in ("draft", "prerelease"):
                    fixture.release[mutation] = True
                elif mutation == "duplicate":
                    fixture.release["assets"].append(fixture.release["assets"][0])
                elif mutation == "asset_digest":
                    fixture.release["assets"][0]["digest"] = "sha256:" + "0" * 64
                elif mutation == "asset_origin":
                    fixture.release["assets"][0]["browser_download_url"] = "https://other.example/secret"
                elif mutation == "tag":
                    fixture.release["tag_name"] = "v1.2.1"
                else:
                    fixture.release["id"] = True if mutation == "boolean_id" else 999
                fixture.urls[fixture.plan["provenance"]["release_api_url"]] = encoded(fixture.release)
                self.refuses(fixture)
                self.assertFalse(fixture.commands)
        fixture = Fixture()
        fixture.urls[artifacts.API + "/git/ref/tags/" + fixture.tag] = encoded({"ref": "refs/tags/" + fixture.tag, "object": {"type": "commit", "sha": "f" * 40}})
        self.directory = Path(self.temp.name)
        self.refuses(fixture)
        self.assertFalse(fixture.commands)

    def test_annotated_cycle_and_depth_are_refused(self):
        fixture = Fixture()
        fixture.urls[artifacts.API + "/git/ref/tags/" + fixture.tag] = encoded({"ref": "refs/tags/" + fixture.tag, "object": {"type": "tag", "sha": "d" * 40}})
        fixture.urls[artifacts.API + "/git/tags/" + "d" * 40] = encoded({"object": {"type": "tag", "sha": "d" * 40}})
        self.refuses(fixture)

    def test_all_raw_download_hashes_are_checked(self):
        for key in ("manifest_url", "digest_url", "history_url"):
            with self.subTest(key=key), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                fixture.urls[fixture.plan["provenance"][key]] += b" "
                self.refuses(fixture)
                self.assertFalse(fixture.commands)
        for key in ("index_digest", "child_digest", "config_digest"):
            with self.subTest(key=key), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                url = artifacts.REGISTRY + ("/blobs/" if key == "config_digest" else "/manifests/") + getattr(fixture, key)
                fixture.urls[url] += b" "
                self.refuses(fixture)
                self.assertFalse(fixture.commands)

    def test_platform_config_descriptor_and_identity_refusals(self):
        for options in ({"duplicate_platform": True}, {"missing_platform": True}, {"variant": "v9"},
                        {"config_architecture": "arm64"}, {"user": "root"}, {"label_version": "1.2.1"},
                        {"duplicate_env": True}, {"child_size_offset": 1}, {"config_size_offset": 1},
                        {"foreign_layer": True}, {"artifact": True}, {"volumes": {"/etc/wayfindr": {}}}):
            with self.subTest(options=options), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture(**options)
                self.refuses(fixture)
                self.assertFalse(fixture.commands)

    def test_manifest_schema_and_history_coverage_match_downloaded_contract(self):
        for mutation in ("target_omitted", "earliest_omitted", "duplicate_version", "future", "target_mismatch", "invalid_action"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                changed = deepcopy(fixture.history)
                if mutation == "target_omitted":
                    changed["releases"].pop()
                elif mutation == "earliest_omitted":
                    changed["releases"].pop(0)
                elif mutation == "duplicate_version":
                    changed["releases"].append(deepcopy(changed["releases"][0]))
                elif mutation == "future":
                    changed["releases"][0]["version"] = "1.3.0"
                elif mutation == "target_mismatch":
                    changed["releases"][-1]["minimum_upgrade_from"] = None
                else:
                    changed["releases"][0]["actions"] = [{}]
                raw = encoded(changed)
                fixture.urls[fixture.plan["provenance"]["history_url"]] = raw
                fixture.plan["provenance"]["history_sha256"] = sha(raw)
                self.refuses(fixture)
                self.assertFalse(fixture.commands)

    def test_baked_history_is_builder_transformation_not_raw_source_copy(self):
        fixture = Fixture()
        fixture.copies["release-history.json"] = fixture.history_raw
        self.refuses(fixture)
        self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])

    def test_baked_history_floor_filters_older_committed_entries(self):
        fixture = Fixture()
        fixture.manifest["minimum_upgrade_from"] = "0.2.0"
        fixture.manifest_raw = encoded(fixture.manifest)
        fixture.history["releases"][-1]["minimum_upgrade_from"] = "0.2.0"
        fixture.history_raw = encoded(fixture.history)
        fixture.plan["declarations"]["history"] = [fixture.history["releases"][0], fixture.manifest]
        fixture.plan["provenance"].update(history_floor="0.2.0", manifest_sha256=sha(fixture.manifest_raw), history_sha256=sha(fixture.history_raw))
        fixture.release["assets"][0]["digest"] = digest(fixture.manifest_raw)
        fixture.urls[fixture.plan["provenance"]["release_api_url"]] = encoded(fixture.release)
        fixture.urls[fixture.plan["provenance"]["manifest_url"]] = fixture.manifest_raw
        fixture.urls[fixture.plan["provenance"]["history_url"]] = fixture.history_raw
        fixture.copies["release.json"] = fixture.manifest_raw
        fixture.copies["release-history.json"] = encoded({"schema": 1, "releases": [fixture.manifest]})
        self.prepare(fixture)

    def test_baked_and_reported_json_types_cannot_coerce_true_to_one(self):
        fixture = Fixture()
        fixture.copies["release-history.json"] = encoded({**fixture.baked, "schema": True})
        self.refuses(fixture)
        (self.directory / "image-release-history.json").unlink()
        with tempfile.TemporaryDirectory() as directory:
            self.directory = Path(directory)
            fixture = Fixture()
            fixture.plan["declarations"]["manifest"] = {**fixture.manifest, "schema": True}
            self.refuses(fixture)
            self.assertFalse(fixture.commands)

    def test_frozen_full_plan_and_no_arbitrary_urls(self):
        for mutation in ("tag", "image", "platform", "url", "hash", "manifest", "history", "complete"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                if mutation == "tag":
                    fixture.target["tag"] = "v1.2.0/../../secrets"
                elif mutation == "image":
                    fixture.plan["target"]["image_reference"] = "evil.example/image@" + fixture.index_digest
                elif mutation == "platform":
                    fixture.plan["target"]["platform"] = "linux/arm64"
                elif mutation == "url":
                    fixture.plan["provenance"]["manifest_url"] = "https://169.254.169.254/secret"
                elif mutation == "hash":
                    fixture.plan["provenance"]["manifest_sha256"] = "uppercase" * 8
                elif mutation == "manifest":
                    fixture.plan["declarations"]["manifest"] = {**fixture.manifest, "minimum_upgrade_from": None}
                elif mutation == "history":
                    fixture.plan["declarations"]["history"] = []
                else:
                    fixture.plan["provenance"]["history_complete"] = False
                self.refuses(fixture)
                self.assertFalse(fixture.commands)

    def test_index_local_id_without_matching_descriptor_is_refused(self):
        fixture = Fixture()
        fixture.actual_id = fixture.index_digest
        self.refuses(fixture)
        self.assertEqual(len(fixture.commands), 2)

    def test_classic_and_containerd_ids_remain_distinct_from_public_config(self):
        for architecture in ("amd64", "arm64"):
            for engine in ("classic", "containerd-index", "containerd-platform"):
                with self.subTest(architecture=architecture, engine=engine), tempfile.TemporaryDirectory() as directory:
                    self.directory = Path(directory)
                    fixture = Fixture(architecture, engine=engine, variant="v8" if architecture == "arm64" else None)
                    evidence = self.prepare(fixture)
                    self.assertEqual(evidence["schema"], 2)
                    self.assertEqual(evidence["config_digest"], fixture.config_digest)
                    self.assertEqual(evidence["local_image_id"], fixture.actual_id)
                    self.assertEqual(evidence["local_image_descriptor"], fixture.descriptor)
                    self.assertEqual(evidence["execution_reference"], artifacts.IMAGE + ":1.2.0@" + fixture.index_digest)
                    self.assertEqual(evidence["execution_platform"], "linux/" + architecture)
                    create = next(command for command in fixture.commands if command[0] == "create")
                    self.assertEqual(create[-1], evidence["execution_reference"])
                    self.assertIn("--platform=" + evidence["execution_platform"], create)
                    self.assertIn("--pull=never", create)
                    self.assertNotEqual(create[-1], fixture.config_digest)
                    inspect = next(command for command in fixture.commands if command[0] == "inspect")
                    expected_format = "{{json .}}"
                    self.assertEqual(inspect[inspect.index("--format") + 1], expected_format)
                    fixture.commands.clear()
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, architecture, self.directory)
                    self.assertEqual(len(fixture.commands), 1)
                    self.assertEqual(fixture.commands[0][:2], ["image", "inspect"])

    def test_classic_legacy_inspect_defaults_preserve_every_published_config_field(self):
        for architecture in ("amd64", "arm64"):
            with self.subTest(architecture=architecture), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture(architecture, legacy_config=True)
                evidence = self.prepare(fixture)
                self.assertEqual(evidence["local_image_id"], fixture.config_digest)
                self.assertIsNone(evidence["local_image_descriptor"])
                self.assertEqual(json.loads((self.directory / "image-config.json").read_bytes())["config"], fixture.config)
                fixture.commands.clear()
                artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, architecture, self.directory)
                self.assertEqual(len(fixture.commands), 1)

    def test_legacy_inspect_nondefault_wrong_types_and_containerd_defaults_are_refused(self):
        defaults = {"Hostname": "", "Domainname": "", "AttachStdin": False, "AttachStdout": False,
                    "AttachStderr": False, "Tty": False, "OpenStdin": False, "StdinOnce": False,
                    "Image": "", "Volumes": None, "OnBuild": None, "WorkingDir": ""}
        for key, default in defaults.items():
            for value in ((True, 0) if default is False else ("changed", False)):
                with self.subTest(key=key, value=value), tempfile.TemporaryDirectory() as directory:
                    self.directory = Path(directory)
                    fixture = Fixture(legacy_config=True)
                    original = fixture.capture
                    def capture(command, *, timeout):
                        code, raw = original(command, timeout=timeout)
                        if command[len(artifacts.DOCKER):][:2] == ["image", "inspect"]:
                            actual = json.loads(raw)
                            actual["Config"][key] = value
                            return code, encoded(actual)
                        return code, raw
                    fixture.capture = capture
                    self.refuses(fixture)
                    self.assertFalse(any(command[0] == "create" for command in fixture.commands))
        for engine in ("containerd-index", "containerd-platform"):
            with self.subTest(engine=engine), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                self.refuses(Fixture(engine=engine, legacy_config=True))

    def test_published_fields_cannot_be_replaced_with_legacy_defaults(self):
        for key, published, observed in (("WorkingDir", "/app/apps/server", ""),
                                         ("Volumes", {"/data": {}}, None), ("OnBuild", ["RUN true"], None)):
            with self.subTest(key=key), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture(legacy_config=True, extra_config={key: published})
                original = fixture.capture
                def capture(command, *, timeout):
                    code, raw = original(command, timeout=timeout)
                    if command[len(artifacts.DOCKER):][:2] == ["image", "inspect"]:
                        actual = json.loads(raw)
                        actual["Config"][key] = observed
                        return code, encoded(actual)
                    return code, raw
                fixture.capture = capture
                self.refuses(fixture)
                self.assertFalse(any(command[0] == "create" for command in fixture.commands))

    def test_full_public_config_rootfs_and_platform_bind_each_local_store(self):
        mutations = ("unknown_id", "os", "architecture", "variant", "variant_type", "rootfs_layer", "rootfs_type",
                     "working_directory", "ports", "healthcheck", "config_extra", "wrong_repository", "wrong_digest")
        for engine in ("classic", "containerd-index", "containerd-platform"):
            for mutation in mutations:
                for phase in ("prepare", "retained"):
                    with self.subTest(engine=engine, mutation=mutation, phase=phase), tempfile.TemporaryDirectory() as directory:
                        self.directory = Path(directory)
                        fixture = Fixture(engine=engine)
                        evidence = self.prepare(fixture) if phase == "retained" else None
                        original = fixture.capture
                        def capture(command, *, timeout):
                            code, raw = original(command, timeout=timeout)
                            if command[len(artifacts.DOCKER):][:2] != ["image", "inspect"]:
                                return code, raw
                            value = json.loads(raw)
                            if mutation == "unknown_id":
                                value["Id"] = "sha256:" + "0" * 64
                            elif mutation == "os":
                                value["Os"] = "windows"
                            elif mutation == "architecture":
                                value["Architecture"] = "arm64"
                            elif mutation == "variant":
                                value["Variant"] = "v9"
                            elif mutation == "variant_type":
                                value["Variant"] = False
                            elif mutation == "rootfs_layer":
                                value["RootFS"]["Layers"][0] = "sha256:" + "0" * 64
                            elif mutation == "rootfs_type":
                                value["RootFS"]["Type"] = "other"
                            elif mutation == "working_directory":
                                value["Config"]["WorkingDir"] = "/tmp/altered"
                            elif mutation == "ports":
                                value["Config"]["ExposedPorts"]["2375/tcp"] = {}
                            elif mutation == "healthcheck":
                                value["Config"]["Healthcheck"]["Test"] = ["NONE"]
                            elif mutation == "config_extra":
                                value["Config"]["UnverifiedProperty"] = "new"
                            elif mutation == "wrong_repository":
                                value["RepoDigests"] = ["ghcr.io/other/wayfindr@" + fixture.index_digest]
                            else:
                                value["RepoDigests"] = [artifacts.IMAGE + "@" + fixture.child_digest]
                            return code, encoded(value)
                        fixture.capture = capture
                        fixture.commands.clear()
                        if phase == "retained":
                            with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                                artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
                            self.assertEqual(len(fixture.commands), 1)
                        else:
                            self.refuses(fixture)
                            self.assertFalse(any(command[0] == "create" for command in fixture.commands))

    def test_containerd_descriptor_digest_media_size_platform_and_shape_are_bound(self):
        for engine in ("containerd-index", "containerd-platform"):
            for mutation in ("absent", "digest", "mediaType", "size", "boolean_size", "platform", "urls", "annotations"):
                with self.subTest(engine=engine, mutation=mutation), tempfile.TemporaryDirectory() as directory:
                    self.directory = Path(directory)
                    fixture = Fixture(engine=engine)
                    if mutation == "absent":
                        fixture.descriptor = None
                    elif mutation == "digest":
                        fixture.descriptor["digest"] = fixture.config_digest
                    elif mutation == "mediaType":
                        fixture.descriptor["mediaType"] = "application/example"
                    elif mutation == "size":
                        fixture.descriptor["size"] += 1
                    elif mutation == "boolean_size":
                        fixture.descriptor["size"] = True
                    elif mutation == "platform":
                        fixture.descriptor["platform"] = {"os": "linux", "architecture": "arm64"}
                    elif mutation == "urls":
                        fixture.descriptor["urls"] = ["https://other.example/image"]
                    else:
                        fixture.descriptor["annotations"] = {"arbitrary": "unverified"}
                    self.refuses(fixture)
                    self.assertFalse(any(command[0] == "create" for command in fixture.commands))
        self.directory = Path(self.temp.name)
        fixture = Fixture()
        fixture.descriptor = {"mediaType": "application/vnd.oci.image.config.v1+json", "digest": fixture.config_digest, "size": len(fixture.config_raw)}
        self.refuses(fixture)

    def test_retained_binding_rejects_valid_store_identity_change_without_mutation(self):
        fixture = Fixture(engine="containerd-index")
        evidence = self.prepare(fixture)
        fixture.actual_id = fixture.child_digest
        fixture.descriptor = next(deepcopy(item) for item in json.loads(fixture.index_raw)["manifests"] if item["digest"] == fixture.child_digest)
        fixture.commands.clear()
        before = {path.name: path.read_bytes() for path in self.directory.iterdir()}
        with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
            artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, evidence, fixture.architecture, self.directory)
        self.assertEqual(len(fixture.commands), 1)
        self.assertEqual(before, {path.name: path.read_bytes() for path in self.directory.iterdir()})

    def test_retained_schema1_and_changed_execution_selector_are_refused(self):
        fixture = Fixture(engine="containerd-index")
        evidence = self.prepare(fixture)
        for field, value in (("schema", 1), ("local_image_id", fixture.config_digest), ("local_image_descriptor", None),
                             ("execution_reference", artifacts.IMAGE + ":1.2.0"), ("execution_reference", artifacts.IMAGE + "@" + fixture.child_digest),
                             ("execution_platform", "linux/arm64")):
            with self.subTest(field=field, value=value):
                changed = {**evidence, field: value}
                (self.directory / "artifacts.json").write_bytes((json.dumps(changed, sort_keys=True, separators=(",", ":")) + "\n").encode())
                fixture.commands.clear()
                with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, changed, fixture.architecture, self.directory)
                if field == "schema":
                    self.assertFalse(fixture.commands)
                self.assertTrue(all(command[:2] == ["image", "inspect"] for command in fixture.commands))

    def test_never_started_probe_binds_image_reference_network_pid_and_mounts(self):
        for mutation in ("image", "reference", "network", "pid", "pid_type", "started", "entrypoint", "mount"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture(engine="containerd-index")
                original = fixture.capture
                def capture(command, *, timeout):
                    code, raw = original(command, timeout=timeout)
                    if command[len(artifacts.DOCKER):][0] != "inspect":
                        return code, raw
                    value = json.loads(raw)
                    if mutation == "image":
                        value["Image"] = fixture.config_digest
                    elif mutation == "reference":
                        value["Config"]["Image"] = artifacts.IMAGE + ":1.2.0"
                    elif mutation == "network":
                        value["HostConfig"]["NetworkMode"] = "bridge"
                    elif mutation == "pid":
                        value["State"]["Pid"] = 42
                    elif mutation == "pid_type":
                        value["State"]["Pid"] = False
                    elif mutation == "started":
                        value["State"]["StartedAt"] = "2026-10-09T21:00:00Z"
                    elif mutation == "entrypoint":
                        value["Config"]["Entrypoint"] = ["wayfindr-entrypoint"]
                    else:
                        value["Mounts"] = [{"Type": "bind", "Source": "/opt/wayfindr", "Destination": "/app"}]
                    return code, encoded(value)
                fixture.capture = capture
                self.refuses(fixture)
                self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])
                self.assertFalse(any(command[0] == "cp" for command in fixture.commands))

    def test_probe_requires_selected_manifest_even_when_index_id_and_reference_match(self):
        for mutation in ("absent", "index_digest", "config_digest", "other_platform", "mediaType", "size", "boolean_size",
                         "architecture", "os", "variant", "urls", "annotations"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture("arm64", engine="containerd-index", variant="v8")
                descriptor = fixture.container_descriptor
                if mutation == "absent":
                    fixture.container_descriptor = None
                elif mutation == "index_digest":
                    descriptor["digest"] = fixture.index_digest
                elif mutation == "config_digest":
                    descriptor["digest"] = fixture.config_digest
                elif mutation == "other_platform":
                    descriptor["digest"] = "sha256:" + "3" * 64
                elif mutation == "mediaType":
                    descriptor["mediaType"] = "application/vnd.oci.image.index.v1+json"
                elif mutation == "size":
                    descriptor["size"] += 1
                elif mutation == "boolean_size":
                    descriptor["size"] = True
                elif mutation == "architecture":
                    descriptor["platform"]["architecture"] = "amd64"
                elif mutation == "os":
                    descriptor["platform"]["os"] = "windows"
                elif mutation == "variant":
                    descriptor["platform"]["variant"] = "v9"
                elif mutation == "urls":
                    descriptor["urls"] = ["https://other.example/image"]
                else:
                    descriptor["annotations"] = {"arbitrary": "unverified"}
                self.refuses(fixture)
                self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])
                self.assertFalse(any(command[0] == "cp" for command in fixture.commands))

    def test_selected_container_manifest_accepts_only_equivalent_optional_arm64_metadata(self):
        for metadata in ("no_platform", "implicit_v8", "explicit_v8"):
            with self.subTest(metadata=metadata), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture("arm64", engine="containerd-index", variant="v8" if metadata != "explicit_v8" else None)
                if metadata == "no_platform":
                    fixture.container_descriptor.pop("platform")
                elif metadata == "implicit_v8":
                    fixture.container_descriptor["platform"].pop("variant")
                else:
                    fixture.container_descriptor["platform"]["variant"] = "v8"
                evidence = self.prepare(fixture)
                self.assertEqual(evidence["platform_manifest_descriptor"], fixture.selected_descriptor)
                self.assertNotEqual(evidence["platform_manifest_digest"], evidence["index_digest"])

    def test_retained_selected_manifest_descriptor_must_equal_verified_public_bytes(self):
        fixture = Fixture("arm64", engine="containerd-index", variant="v8")
        evidence = self.prepare(fixture)
        for key, value in (("digest", fixture.index_digest), ("size", 1), ("platform", {"os": "linux", "architecture": "amd64"})):
            with self.subTest(key=key):
                changed = deepcopy(evidence)
                changed["platform_manifest_descriptor"][key] = value
                (self.directory / "artifacts.json").write_bytes((json.dumps(changed, sort_keys=True, separators=(",", ":")) + "\n").encode())
                fixture.commands.clear()
                with self.assertRaisesRegex(Refusal, r"^artifact_verification_failed$"):
                    artifacts.Artifacts(fixture, fixture.fetch).verify(fixture.target, changed, fixture.architecture, self.directory)
                self.assertFalse(fixture.commands)

    def test_local_identity_is_revalidated_after_probe_before_receipt(self):
        fixture = Fixture(engine="containerd-index")
        original = fixture.capture
        inspections = 0
        def capture(command, *, timeout):
            nonlocal inspections
            code, raw = original(command, timeout=timeout)
            if command[len(artifacts.DOCKER):][:2] == ["image", "inspect"]:
                inspections += 1
                if inspections == 2:
                    value = json.loads(raw)
                    value["Config"]["WorkingDir"] = "/tmp/replaced"
                    return code, encoded(value)
            return code, raw
        fixture.capture = capture
        self.refuses(fixture)
        self.assertEqual(inspections, 2)
        self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])

    def test_exact_baked_manifest_and_source_files(self):
        for name in ("version", "commit", "release.json"):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                fixture.copies[name] += b" "
                self.refuses(fixture)
                self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])

    def test_probe_never_started_and_copied_links_or_duplicates_refused(self):
        for kind, duplicate in ((tarfile.SYMTYPE, False), (tarfile.LNKTYPE, False), (tarfile.REGTYPE, True)):
            with self.subTest(kind=kind, duplicate=duplicate), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                fixture.copy_kind, fixture.copy_duplicate = kind, duplicate
                self.refuses(fixture)
                self.assertEqual(fixture.commands[-1], ["rm", "--volumes", fixture.id])
        self.directory = Path(self.temp.name)
        fixture = Fixture()
        fixture.state = "running"
        self.refuses(fixture)
        self.assertFalse(any(command[0] == "cp" for command in fixture.commands))

    def test_download_and_command_failures_are_redacted(self):
        for failure in ("fetch", "pull", "create", "cp", "rm"):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                fixture.failure = failure
                self.refuses(fixture)

    def test_overlarge_body_duplicate_json_and_nonfinite_metadata(self):
        for raw in (b"x" * (artifacts.MAXIMUM + 1), b'{"id":123,"id":123}', b'{"id":NaN}', b'\xef\xbb\xbf{"id":123}', b'{"id":"\\ud800"}'):
            with self.subTest(raw=raw[:30]), tempfile.TemporaryDirectory() as directory:
                self.directory = Path(directory)
                fixture = Fixture()
                fixture.urls[fixture.plan["provenance"]["release_api_url"]] = raw
                self.refuses(fixture)
                self.assertFalse(fixture.commands)

    def test_deadline_is_checked_during_downloads(self):
        fixture = Fixture()
        with patch.object(artifacts.time, "monotonic", side_effect=[0, 0, 0, artifacts.TIMEOUT + 1]):
            self.refuses(fixture)
        self.assertEqual(len(fixture.fetches), 1)
        self.assertFalse(fixture.commands)

    def test_private_directory_and_existing_artifact_cannot_change(self):
        fixture = Fixture()
        (self.directory / "release-api.json").write_bytes(b"wrong")
        os.chmod(self.directory / "release-api.json", 0o600)
        self.refuses(fixture)
        self.assertFalse(fixture.commands)
        (self.directory / "release-api.json").unlink()
        os.chmod(self.directory, 0o755)
        self.refuses(Fixture())

    def test_redirect_origins_credentials_protocol_and_limit(self):
        request = urllib.request.Request(artifacts.REGISTRY + "/blobs/sha256:" + "a" * 64, headers={"Authorization": "Bearer public-token"})
        redirect = artifacts._Redirect({"ghcr.io", "pkg-containers.githubusercontent.com"})
        result = redirect.redirect_request(request, None, 302, "found", {}, "https://pkg-containers.githubusercontent.com/blob")
        self.assertFalse(result.has_header("Authorization"))
        for url in ("http://pkg-containers.githubusercontent.com/blob", "https://evil.example/blob", "https://user:password@ghcr.io/blob", "https://ghcr.io:444/blob", "https://ghcr.io/blob#fragment"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                artifacts._Redirect({"ghcr.io"}).redirect_request(request, None, 302, "found", {}, url)
        redirect = artifacts._Redirect({"ghcr.io"})
        for _ in range(3):
            redirect.redirect_request(request, None, 302, "found", {}, "https://ghcr.io/blob")
        with self.assertRaises(ValueError):
            redirect.redirect_request(request, None, 302, "found", {}, "https://ghcr.io/blob")

    def test_transport_worker_schema_fixed_urls_and_headers(self):
        request = {"url": artifacts.TOKEN, "headers": {"Accept": "application/json"}, "maximum": 4096, "deadline": time.monotonic() + 30}
        with patch.object(artifacts, "_transport", return_value=b"public") as transport:
            self.assertEqual(artifacts._worker(request), b"public")
            transport.assert_called_once_with(**request)
        for changed in ({**request, "url": "https://evil.example/data"}, {**request, "maximum": True},
                        {**request, "deadline": time.monotonic() - 1}, {**request, "headers": {"Authorization": "Bearer token"}},
                        {**request, "headers": {"Accept": "application/json\r\nSecret: data"}}, {**request, "extra": "argument"}):
            with self.subTest(changed=changed), patch.object(artifacts, "_transport") as transport, self.assertRaises(ValueError):
                artifacts._worker(changed)
            transport.assert_not_called()

    def test_default_fetch_process_is_bounded_and_has_no_ambient_credentials(self):
        worker = self.directory / "network-fixture.py"
        prefix = 'import json,os,sys,time\nr=json.loads(sys.stdin.buffer.read())\nassert "Authorization" not in str(sys.argv)\nassert not any(k in os.environ for k in ("HTTPS_PROXY","SSL_CERT_FILE","GITHUB_TOKEN"))\n'
        worker.write_text(prefix + 'sys.stdout.buffer.write(b"fixture")\n')
        start_child = artifacts.subprocess.Popen
        def spawn(command, **options):
            self.assertEqual("/usr/bin/python3", command[0])
            # The isolated Python image places its interpreter in /usr/local;
            # production's fixed system interpreter remains asserted above.
            return start_child([sys.executable, *command[1:]], **options)
        with patch.object(artifacts.subprocess, "Popen", side_effect=spawn), patch.object(artifacts, "__file__", str(worker)), patch.dict(os.environ, {"HTTPS_PROXY": "secret-proxy", "SSL_CERT_FILE": "/wrong", "GITHUB_TOKEN": "secret-token"}):
            self.assertEqual(artifacts._fetch(artifacts.TOKEN, headers={}, maximum=100, deadline=time.monotonic() + 5), b"fixture")
        worker.write_text(prefix + 'sys.stdout.buffer.write(b"x"*129)\n')
        with patch.object(artifacts.subprocess, "Popen", side_effect=spawn), patch.object(artifacts, "__file__", str(worker)), self.assertRaises(ValueError):
            artifacts._fetch(artifacts.TOKEN, headers={}, maximum=128, deadline=time.monotonic() + 5)
        worker.write_text(prefix + 'time.sleep(5)\n')
        start = time.monotonic()
        with patch.object(artifacts.subprocess, "Popen", side_effect=spawn), patch.object(artifacts, "__file__", str(worker)), self.assertRaises(ValueError):
            artifacts._fetch(artifacts.TOKEN, headers={}, maximum=100, deadline=start + 0.15)
        self.assertLess(time.monotonic() - start, 2)

    def test_socket_reader_bounds_headers_chunks_and_remaining_deadline(self):
        class Socket:
            def __init__(self):
                self.timeouts = []
            def settimeout(self, value):
                self.timeouts.append(value)
        sock = Socket()
        reader = artifacts._DeadlineReader(io.BytesIO(b"HTTP/1.1 200 OK\r\nHeader: public\r\n\r\nbody"), sock, 5)
        with patch.object(artifacts.time, "monotonic", return_value=1):
            self.assertEqual(reader.readline(65_536), b"HTTP/1.1 200 OK\r\n")
            self.assertEqual(reader.readline(65_536), b"Header: public\r\n")
            self.assertEqual(reader.readline(65_536), b"\r\n")
            self.assertEqual(reader.read(4), b"body")
        self.assertTrue(all(value == 4 for value in sock.timeouts))
        with patch.object(artifacts.time, "monotonic", return_value=5), self.assertRaises(ValueError):
            reader.read1(1)


if __name__ == "__main__":
    unittest.main()
