#!/usr/bin/env python3
"""Publication-gate fixtures: no network, credentials, image pulls or VM writes."""

from copy import deepcopy
import base64
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request


MODULE = Path(__file__).parent / "self-host/update_vm_preflight.py"
spec = importlib.util.spec_from_file_location("update_vm_preflight", MODULE)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


def encoded(value):
    return (json.dumps(value, indent=2) + "\n").encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def blob_sha(raw):
    return hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()


class Fixture:
    def __init__(self):
        self.urls = {}
        self.fetches = []
        self.source = "v1.2.0"
        self.target = "v1.2.1"
        self.add(self.source, "a" * 40, "c" * 40, 10)
        self.add(self.target, "b" * 40, "d" * 40, 11)

    def add(self, tag, commit, tree_sha, release_id):
        manifest = {"schema": 1, "version": tag[1:], "commit": commit,
                    "requires_operator_action": False, "minimum_upgrade_from": "0.1.0-alpha.1", "actions": []}
        raw = encoded(manifest)
        digest = ("sha256:" + sha(tag.encode()) + "\n").encode()
        assets = []
        for name, data in (("release-manifest.json", raw), ("release-image-digest.txt", digest)):
            url = preflight.RELEASES + "/download/" + tag + "/" + name
            assets.append({"name": name, "state": "uploaded", "size": len(data),
                           "digest": "sha256:" + sha(data), "browser_download_url": url})
            self.urls[url] = data
        self.urls[preflight.API + "/releases/tags/" + tag] = encoded({
            "id": release_id, "tag_name": tag, "draft": False, "prerelease": False,
            "html_url": preflight.RELEASES + "/tag/" + tag, "assets": assets,
        })
        self.urls[preflight.API + "/git/ref/tags/" + tag] = encoded({
            "ref": "refs/tags/" + tag, "object": {"type": "commit", "sha": commit},
        })
        self.urls[preflight.API + "/git/commits/" + commit] = encoded({"sha": commit, "tree": {"sha": tree_sha}})
        tree = []
        for path in preflight.REQUIRED_FILES:
            if path == preflight.UPDATER:
                data = b'VERSION = "0.4.0"\nPROTOCOL = 1\n'
            elif path == preflight.INSTALLATION:
                data = b"<?php\nfinal class InstallationCapabilities {\npublic const HELPER_PROTOCOL = 1;\npublic const MINIMUM_HELPER_VERSION = '0.4.0';\npublic const REQUIRED_HELPER_CAPABILITIES = ['plan', 'status', 'start', 'history', 'cancel'];\n}\n"
            elif path == preflight.PROBE:
                data = b"<?php\nfinal class HostUpdaterStatusCommand {\nprotected $signature = 'wayfindr:updater-status {--protocol-contract}';\npublic function handle() {\nif ($this->option('protocol-contract')) {\n$this->line(json_encode(['schema' => 1,\n'protocol' => InstallationCapabilities::HELPER_PROTOCOL,\n'minimum_helper_version' => InstallationCapabilities::MINIMUM_HELPER_VERSION,\n'capabilities' => InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES]));\n}\n}\n}\n"
            else:
                data = b"published file\n"
            item_sha = blob_sha(data)
            self.urls[preflight.API + "/git/blobs/" + item_sha] = encoded({
                "sha": item_sha, "encoding": "base64", "size": len(data), "content": base64.b64encode(data).decode(),
            })
            tree.append({"path": path, "mode": "100644", "type": "blob", "sha": item_sha})
        self.urls[preflight.API + "/git/trees/" + tree_sha + "?recursive=1"] = encoded({
            "sha": tree_sha, "truncated": False, "tree": tree,
        })

    def fetch(self, url):
        self.fetches.append(url)
        if url not in self.urls:
            raise urllib.error.HTTPError(url, 404, "not found, private token must not appear", {}, None)
        value = self.urls[url]
        if isinstance(value, Exception):
            raise value
        return value

    def change(self, url, transform):
        value = json.loads(self.urls[url])
        transform(value)
        self.urls[url] = encoded(value)

    def release_url(self, tag=None):
        return preflight.API + "/releases/tags/" + (tag or self.target)

    def tree_url(self, tag=None):
        commit = json.loads(self.urls[preflight.API + "/git/ref/tags/" + (tag or self.target)])["object"]["sha"]
        tree = json.loads(self.urls[preflight.API + "/git/commits/" + commit])["tree"]["sha"]
        return preflight.API + "/git/trees/" + tree + "?recursive=1"

    def helper_files(self, tag, *, current=False):
        """A complete synthetic public tree; current copies are never executed."""
        root = MODULE.parents[2]
        url = self.tree_url(tag)
        tree = json.loads(self.urls[url])
        wanted = tuple("scripts/self-host/" + name for name in preflight.HELPER_FILES) + preflight.PROVENANCE_FILES
        if current:
            wanted += (preflight.UPGRADE,)
        for path in wanted:
            entry = next((entry for entry in tree["tree"] if entry["path"] == path), None)
            if current or path in preflight.PROVENANCE_FILES or path.endswith(('update_protection.py', 'protection_archive.py')):
                raw = (root / path).read_bytes()
            elif path == preflight.UPDATER:
                raw = b'VERSION = "0.4.0"\nPROTOCOL = 1\n'
            else:
                raw = ("published old " + path + "\n").encode()
            sha = blob_sha(raw)
            self.urls[preflight.API + "/git/blobs/" + sha] = encoded({
                "sha": sha, "encoding": "base64", "size": len(raw), "content": base64.b64encode(raw).decode(),
            })
            if entry is None:
                tree["tree"].append({"path": path, "mode": "100644", "type": "blob", "sha": sha})
            else:
                entry["sha"] = sha
        self.urls[url] = encoded(tree)

    def rewrite_blob(self, path, transform, tag=None):
        url = self.tree_url(tag)
        tree = json.loads(self.urls[url])
        entry = next(entry for entry in tree["tree"] if entry["path"] == path)
        old = json.loads(self.urls[preflight.API + "/git/blobs/" + entry["sha"]])
        raw = transform(base64.b64decode(old["content"]))
        entry["sha"] = blob_sha(raw)
        self.urls[preflight.API + "/git/blobs/" + entry["sha"]] = encoded({
            "sha": entry["sha"], "encoding": "base64", "size": len(raw), "content": base64.b64encode(raw).decode(),
        })
        self.urls[url] = encoded(tree)

    def rewrite_asset(self, name, transform):
        url = preflight.RELEASES + "/download/" + self.target + "/" + name
        raw = transform(self.urls[url])
        self.urls[url] = raw
        self.change(self.release_url(), lambda value: next(asset for asset in value["assets"] if asset["name"] == name).update(
            size=len(raw), digest="sha256:" + sha(raw)))


class PublicationGateTest(unittest.TestCase):
    def setUp(self):
        self.fixture = Fixture()

    def assess(self):
        return preflight.assess(self.fixture.source, self.fixture.target, fetch=self.fixture.fetch)

    def blocked(self, code, role="target"):
        report = self.assess()
        self.assertEqual(report["status"], "blocked")
        self.assertIn({"code": code, "role": role}, report["reasons"])
        self.assertFalse(report["qualification"])
        self.assertFalse(report["verification"]["baked_image"])
        return report

    def test_ready_is_only_a_publication_prerequisite_with_exact_identities(self):
        report = self.assess()
        self.assertEqual(report["schema"], 1)
        self.assertEqual(report["status"], "ready")
        self.assertEqual(report["reasons"], [])
        self.assertEqual(report["scope"], "published_artifact_declarations")
        self.assertEqual(report["source"]["commit"], "a" * 40)
        self.assertEqual(report["target"]["commit"], "b" * 40)
        target = report["target"]
        self.assertEqual(target["image_reference"], preflight.IMAGE + ":1.2.1@" + target["image_digest"])
        self.assertEqual(target["manifest_sha256"], sha(self.fixture.urls[preflight.RELEASES + "/download/v1.2.1/release-manifest.json"]))
        self.assertEqual(target["helper_version"], "0.4.0")
        self.assertEqual(target["helper_protocol"], 1)
        self.assertEqual(set(target["required_files"]), set(preflight.REQUIRED_FILES))
        self.assertEqual(report["verification"], {"public_release_metadata": True, "source_tree_declarations": True,
                                                "baked_image": False, "release_guards": False, "host": False, "reboot": False})
        self.assertFalse(report["qualification"])
        self.assertLessEqual(len(self.fixture.fetches), preflight.MAX_FETCHES)
        self.assertTrue(all(preflight._valid_url(url) for url in self.fixture.fetches))

    def test_reviewed_current_protocol_sources_can_pass_the_declaration_gate(self):
        root = MODULE.parents[2]
        for path in (preflight.UPDATER, preflight.INSTALLATION, preflight.PROBE):
            self.fixture.rewrite_blob(path, lambda _, path=path: (root / path).read_bytes())
        report = self.assess()
        self.assertEqual(report["status"], "ready")
        self.assertTrue(report["target"]["protocol_probe_present"])
        self.assertFalse(report["qualification"])

    def helper_gate(self, helper=None):
        return preflight.assess(self.fixture.source, self.fixture.target, fetch=self.fixture.fetch,
                                helper_tag=helper or self.fixture.target)

    def test_exact_complete_published_helper_bytes_and_cli_are_hashed_without_execution(self):
        self.fixture.helper_files(self.fixture.source)
        self.fixture.helper_files(self.fixture.target, current=True)
        report = self.helper_gate()
        self.assertEqual(report['status'], 'ready', report['reasons'])
        source, selected = report['helpers']['source'], report['helpers']['selected']
        self.assertEqual(source['helper_version'], '0.4.0')
        self.assertEqual(selected['helper_version'], '0.5.0')
        self.assertEqual(selected['commit'], report['target']['commit'])
        self.assertEqual(selected['source_tree_sha'], report['target']['source_tree_sha'])
        self.assertEqual(set(selected['files']), set(preflight.HELPER_FILES))
        root = MODULE.parents[2]
        self.assertEqual(selected['files'], {name: sha((root / 'scripts/self-host' / name).read_bytes())
                                            for name in preflight.HELPER_FILES})
        declaration = {key: selected[key] for key in ('schema', 'helper_version', 'protocol', 'files')}
        self.assertEqual(selected['bundle_sha256'], sha((json.dumps(declaration, sort_keys=True, separators=(',', ':')) + '\n').encode()))
        self.assertEqual(selected['upgrade_cli_sha256'], sha((root / preflight.UPGRADE).read_bytes()))
        self.assertIsNone(source['upgrade_cli_sha256'], 'old enrollment did not ship an upgrade CLI')
        self.assertEqual(len(self.fixture.fetches), len(set(self.fixture.fetches)), 'each exact public URL is fetched once')
        self.assertLessEqual(len(self.fixture.fetches), preflight.MAX_FETCHES)
        self.assertFalse(report['qualification'])

    def test_helper_release_is_independent_of_application_target(self):
        self.fixture.helper_files(self.fixture.source)
        self.fixture.helper_files(self.fixture.target, current=True)
        self.fixture.add('v1.2.2', 'e' * 40, 'f' * 40, 12)
        self.fixture.helper_files('v1.2.2', current=True)
        report = self.helper_gate('v1.2.2')
        self.assertEqual(report['status'], 'ready', report['reasons'])
        self.assertEqual(report['target']['tag'], 'v1.2.1')
        self.assertEqual(report['helpers']['selected']['tag'], 'v1.2.2')
        self.assertEqual(report['helpers']['selected']['commit'], 'e' * 40)
        self.assertGreater(len(self.fixture.fetches), preflight.MAX_FETCHES)
        self.assertLessEqual(len(self.fixture.fetches), preflight.MAX_HELPER_FETCHES)

    def test_missing_archive_module_and_unverified_cli_blob_block_helper_gate(self):
        for missing in ('scripts/self-host/protection_archive.py', preflight.UPGRADE):
            fixture = Fixture()
            fixture.helper_files(fixture.source)
            fixture.helper_files(fixture.target, current=True)
            tree_url = fixture.tree_url()
            tree = json.loads(fixture.urls[tree_url])
            entry = next(entry for entry in tree['tree'] if entry['path'] == missing)
            if missing.endswith('protection_archive.py'):
                tree['tree'].remove(entry)
                fixture.urls[tree_url] = encoded(tree)
                code = 'helper_bundle_unpublished'
            else:
                blob_url = preflight.API + '/git/blobs/' + entry['sha']
                fixture.change(blob_url, lambda blob: blob.update(content=base64.b64encode(b'changed code').decode()))
                code = 'source_blob_invalid'
            with self.subTest(missing=missing):
                report = preflight.assess(fixture.source, fixture.target, fetch=fixture.fetch, helper_tag=fixture.target)
                self.assertEqual(report['status'], 'blocked')
                self.assertIn({'code': code, 'role': 'helper'}, report['reasons'])

    def test_helper_gate_never_imports_a_downloaded_upgrade_script(self):
        self.fixture.helper_files(self.fixture.source)
        self.fixture.helper_files(self.fixture.target, current=True)
        raw = b'raise RuntimeError("downloaded code must never run")\n'
        self.fixture.rewrite_blob(preflight.UPGRADE, lambda _: raw)
        report = self.helper_gate()
        self.assertEqual(report['status'], 'ready')
        self.assertEqual(report['helpers']['selected']['upgrade_cli_sha256'], sha(raw))

    def test_invalid_helper_tag_refuses_before_any_public_fetch(self):
        report = self.helper_gate('main')
        self.assertEqual(report['status'], 'blocked')
        self.assertIn({'code': 'stable_tag_required', 'role': 'helper'}, report['reasons'])
        self.assertEqual(self.fixture.fetches, [])

    def test_noncanonical_tags_are_rejected_before_any_fetch(self):
        for value in (None, True, [], "", "1.2.0", "latest", "main", "v01.2.0", "v1.2.0-alpha.1", "v1.2.0+build", "v1.2.0\n", "v1.2.0/../../secret", "v" + "1" * 130 + ".2.0"):
            with self.subTest(value=value):
                for source, target in ((value, self.fixture.target), (self.fixture.source, value)):
                    report = preflight.assess(source, target, fetch=self.fixture.fetch)
                    self.assertEqual(report["status"], "blocked")
                    self.assertEqual(report["reasons"][0]["code"], "stable_tag_required")
        self.assertEqual(self.fixture.fetches, [])

    def test_same_or_older_target_does_not_fetch(self):
        for target in ("v1.2.0", "v1.1.9", "v0.99.99"):
            with self.subTest(target=target):
                report = preflight.assess(self.fixture.source, target, fetch=self.fixture.fetch)
                self.assertEqual(report["reasons"], [{"code": "target_must_be_newer", "role": "pair"}])
        self.assertEqual(self.fixture.fetches, [])

    def test_missing_published_release_is_classified_without_error_body(self):
        del self.fixture.urls[self.fixture.release_url()]
        report = self.blocked("public_artifact_not_found")
        self.assertNotIn("private token", json.dumps(report))

    def test_prerelease_draft_mismatched_or_false_release_metadata_is_refused(self):
        for field, value in (("draft", True), ("prerelease", True), ("draft", 0), ("id", True), ("id", 0),
                             ("tag_name", "v1.2.2"), ("html_url", "https://github.com/other/repository/releases/tag/v1.2.1")):
            with self.subTest(field=field, value=value):
                self.fixture = Fixture()
                self.fixture.change(self.fixture.release_url(), lambda release: release.update({field: value}))
                self.blocked("published_release_invalid")

    def test_missing_duplicate_foreign_or_unchk_asset_is_refused(self):
        for mutation, code in (
            (lambda release: release.update(assets=[]), "release_asset_missing"),
            (lambda release: release["assets"].append(deepcopy(release["assets"][0])), "release_asset_invalid"),
            (lambda release: release["assets"][0].update(browser_download_url="https://other.example/secret"), "release_asset_invalid"),
            (lambda release: release["assets"][0].update(digest=None), "release_asset_invalid"),
            (lambda release: release["assets"][0].update(size=True), "release_asset_invalid"),
        ):
            with self.subTest(code=code):
                self.fixture = Fixture()
                self.fixture.change(self.fixture.release_url(), mutation)
                self.blocked(code)
                self.assertTrue(all("other.example" not in url for url in self.fixture.fetches))

    def test_asset_checksum_and_size_are_checked_against_published_api(self):
        for mutation in (lambda asset: asset.update(digest="sha256:" + "0" * 64), lambda asset: asset.update(size=1)):
            with self.subTest(mutation=mutation):
                self.fixture = Fixture()
                self.fixture.change(self.fixture.release_url(), lambda release: mutation(release["assets"][0]))
                self.blocked("release_asset_checksum_invalid")

    def test_manifest_is_bound_to_requested_tag_commit_and_schema(self):
        for field, value in (("schema", True), ("schema", 2), ("version", "1.2.0"), ("commit", "e" * 40),
                             ("requires_operator_action", "false"), ("actions", {}), ("minimum_upgrade_from", "latest")):
            with self.subTest(field=field, value=value):
                self.fixture = Fixture()
                self.fixture.rewrite_asset("release-manifest.json", lambda raw: encoded({**json.loads(raw), field: value}))
                self.blocked("release_manifest_invalid")

    def test_malformed_duplicate_or_nonfinite_json_is_refused(self):
        for raw in (b"[]", b'{"id":1,"id":2}', b'{"id": NaN}', b"\xff", b"{" + b"[" * 10000):
            with self.subTest(raw=raw[:30]):
                self.fixture = Fixture()
                self.fixture.urls[self.fixture.release_url()] = raw
                self.blocked("public_metadata_invalid")

    def test_image_digest_has_only_exact_sha256_and_optional_one_newline(self):
        for raw in (b"sha256:" + b"A" * 64, b"sha256:" + b"a" * 64 + b"\n\n", b" sha256:" + b"a" * 64,
                    b"sha256:" + b"a" * 64 + b"\r\n", b"\xff"):
            with self.subTest(raw=raw[:20]):
                self.fixture = Fixture()
                self.fixture.rewrite_asset("release-image-digest.txt", lambda _: raw)
                self.blocked("image_digest_invalid")

    def test_tag_ref_must_name_the_requested_tag(self):
        self.fixture.change(preflight.API + "/git/ref/tags/" + self.fixture.target,
                            lambda value: value.update(ref="refs/tags/v9.9.9"))
        self.blocked("tag_identity_invalid")

    def test_annotated_tag_chain_resolves_to_the_manifest_commit(self):
        first, second = "e" * 40, "f" * 40
        self.fixture.change(preflight.API + "/git/ref/tags/" + self.fixture.target,
                            lambda value: value.update(object={"type": "tag", "sha": first}))
        self.fixture.urls[preflight.API + "/git/tags/" + first] = encoded({
            "sha": first, "tag": self.fixture.target, "object": {"type": "tag", "sha": second},
        })
        self.fixture.urls[preflight.API + "/git/tags/" + second] = encoded({
            "sha": second, "tag": "previous-annotation", "object": {"type": "commit", "sha": "b" * 40},
        })
        self.assertEqual(self.assess()["status"], "ready")

    def test_tag_chain_cycle_and_depth_bound_do_not_fetch_forever(self):
        first = "e" * 40
        self.fixture.change(preflight.API + "/git/ref/tags/" + self.fixture.target,
                            lambda value: value.update(object={"type": "tag", "sha": first}))
        self.fixture.urls[preflight.API + "/git/tags/" + first] = encoded({
            "sha": first, "tag": self.fixture.target, "object": {"type": "tag", "sha": first},
        })
        self.blocked("tag_chain_invalid")
        self.assertEqual(self.fixture.fetches.count(preflight.API + "/git/tags/" + first), 1)
        self.fixture = Fixture()
        self.fixture.change(preflight.API + "/git/ref/tags/" + self.fixture.target,
                            lambda value: value.update(object={"type": "tag", "sha": first}))
        for index in range(preflight.MAX_TAG_CHAIN + 1):
            current = first if index == 0 else hashlib.sha1(str(index).encode()).hexdigest()
            following = hashlib.sha1(str(index + 1).encode()).hexdigest()
            self.fixture.urls[preflight.API + "/git/tags/" + current] = encoded({
                "sha": current, "tag": self.fixture.target, "object": {"type": "tag", "sha": following},
            })
        self.blocked("tag_chain_invalid")
        self.assertLessEqual(len(self.fixture.fetches), preflight.MAX_FETCHES)

    def test_false_annotated_tag_identity_is_refused(self):
        first = "e" * 40
        self.fixture.change(preflight.API + "/git/ref/tags/" + self.fixture.target,
                            lambda value: value.update(object={"type": "tag", "sha": first}))
        self.fixture.urls[preflight.API + "/git/tags/" + first] = encoded({
            "sha": first, "tag": "v8.8.8", "object": {"type": "commit", "sha": "b" * 40},
        })
        self.blocked("tag_identity_invalid")

    def test_old_public_source_blocks_even_when_target_has_the_protocol(self):
        self.fixture.change(self.fixture.tree_url(self.fixture.source), lambda value: value.update(tree=[]))
        report = self.blocked("helper_not_published", role="source")
        self.assertTrue(report["verification"]["public_release_metadata"])
        self.assertTrue(report["target"]["source_tree_verified"])
        self.assertEqual(report["source"]["commit"], "a" * 40)

    def test_missing_u7_view_blocks_published_target(self):
        self.fixture.change(self.fixture.tree_url(), lambda value: value.update(
            tree=[entry for entry in value["tree"] if entry["path"] != "apps/server/resources/views/operator/updates.blade.php"]))
        self.blocked("helper_not_published")

    def test_source_and_target_must_publish_the_boot_runtime_rule(self):
        for role in ("source", "target"):
            with self.subTest(role=role):
                self.fixture = Fixture()
                tag = self.fixture.source if role == "source" else self.fixture.target
                self.fixture.change(self.fixture.tree_url(tag), lambda value: value.update(
                    tree=[entry for entry in value["tree"] if entry["path"] != "docker/self-hosting/wayfindr-updater.conf"]))
                self.blocked("helper_not_published", role=role)

    def test_published_boot_runtime_rule_must_be_a_regular_file(self):
        for role in ("source", "target"):
            with self.subTest(role=role):
                self.fixture = Fixture()
                tag = self.fixture.source if role == "source" else self.fixture.target
                def symlink_rule(value):
                    rule = next(entry for entry in value["tree"] if entry["path"] == "docker/self-hosting/wayfindr-updater.conf")
                    rule["mode"] = "120000"
                self.fixture.change(self.fixture.tree_url(tag), symlink_rule)
                self.blocked("source_tree_invalid", role=role)

    def test_incomplete_or_mismatched_tree_is_refused(self):
        for field, value in (("truncated", True), ("truncated", 0), ("sha", "e" * 40), ("tree", {})):
            with self.subTest(field=field, value=value):
                self.fixture = Fixture()
                self.fixture.change(self.fixture.tree_url(), lambda tree: tree.update({field: value}))
                self.blocked("source_tree_invalid")

    def test_duplicate_tree_paths_and_symlinked_required_files_are_refused(self):
        for mutation in (lambda value: value["tree"].append(deepcopy(value["tree"][0])),
                         lambda value: value["tree"][0].update(mode="120000"),
                         lambda value: value["tree"][0].update(mode={}),
                         lambda value: value["tree"][0].update(type="tree")):
            with self.subTest(mutation=mutation):
                self.fixture = Fixture()
                self.fixture.change(self.fixture.tree_url(), mutation)
                self.blocked("source_tree_invalid")

    def test_source_blob_bytes_are_verified_against_git_blob_hash(self):
        tree = json.loads(self.fixture.urls[self.fixture.tree_url()])
        item = next(entry for entry in tree["tree"] if entry["path"] == preflight.UPDATER)
        self.fixture.change(preflight.API + "/git/blobs/" + item["sha"], lambda blob: blob.update(
            content=base64.b64encode(b'VERSION = "0.4.0"\nPROTOCOL = 2\n').decode()))
        self.blocked("source_blob_invalid")

    def test_old_helper_wrong_protocol_or_nonliteral_version_is_refused(self):
        for source in (b'VERSION = "0.3.0"\nPROTOCOL = 1\n', b'VERSION = "0.4.0"\nPROTOCOL = True\n',
                       b'VERSION = "0.4.0"\nPROTOCOL = 2\n', b'VERSION = dangerous()\nPROTOCOL = 1\n',
                       b'VERSION = "0.4.0"\nVERSION = "0.4.0"\nPROTOCOL = 1\n'):
            with self.subTest(source=source):
                self.fixture = Fixture()
                self.fixture.rewrite_blob(preflight.UPDATER, lambda _: source)
                self.blocked("helper_protocol_unpublished")

    def test_false_app_protocol_declarations_are_refused(self):
        for before, after in ((b"HELPER_PROTOCOL = 1", b"HELPER_PROTOCOL = 2"),
                              (b"MINIMUM_HELPER_VERSION = '0.4.0'", b"MINIMUM_HELPER_VERSION = '0.3.0'"),
                              (b"'history', 'cancel'", b"'history'")):
            with self.subTest(before=before):
                self.fixture = Fixture()
                self.fixture.rewrite_blob(preflight.INSTALLATION, lambda raw: raw.replace(before, after))
                self.blocked("helper_protocol_unpublished")

    def test_protocol_declarations_in_comments_do_not_enable_enrollment(self):
        self.fixture.rewrite_blob(preflight.INSTALLATION, lambda raw: b"<?php /* " + raw + b" */")
        self.blocked("helper_protocol_unpublished")

    def test_protocol_declarations_in_quoted_examples_do_not_enable_enrollment(self):
        self.fixture.rewrite_blob(preflight.INSTALLATION, lambda raw: b'<?php $example = "' + raw + b'";')
        self.blocked("helper_protocol_unpublished")

    def test_protocol_probe_must_actually_declare_the_fixed_contract_fields(self):
        self.fixture.rewrite_blob(preflight.PROBE, lambda raw: raw.replace(
            b"'capabilities' => InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES", b"'capabilities' => []"))
        self.blocked("helper_protocol_unpublished")

    def test_probe_contract_in_a_quoted_example_is_not_a_protocol_probe(self):
        self.fixture.rewrite_blob(preflight.PROBE, lambda raw: b'<?php $example = "' + raw + b'";')
        self.blocked("helper_protocol_unpublished")

    def test_target_upgrade_floor_can_block_the_pair_before_vm_writes(self):
        self.fixture.rewrite_asset("release-manifest.json", lambda raw: encoded({**json.loads(raw), "minimum_upgrade_from": "1.2.1"}))
        self.blocked("upgrade_floor_not_met", role="pair")

    def test_fetch_errors_are_classified_and_never_echo_raw_provider_bodies(self):
        for error, code in ((RuntimeError("secret=forbidden"), "public_metadata_unavailable"),
                            (TimeoutError("secret=forbidden"), "public_fetch_timeout"),
                            (preflight.Refusal("secret=forbidden"), "public_metadata_unavailable")):
            with self.subTest(error=type(error).__name__):
                self.fixture = Fixture()
                self.fixture.urls[self.fixture.release_url()] = error
                report = self.blocked(code)
                self.assertNotIn("secret=", json.dumps(report))

    def test_response_type_and_size_are_bounded_for_injected_fetch(self):
        for value, code in (("{}", "public_metadata_invalid"), (b" " * (preflight.MAX_BODY + 1), "public_response_too_large")):
            with self.subTest(code=code):
                self.fixture = Fixture()
                self.fixture.urls[self.fixture.release_url()] = value
                self.blocked(code)

    def test_cli_emits_classified_json_and_distinguishes_exit_codes(self):
        for ready in (True, False):
            with self.subTest(ready=ready), tempfile.TemporaryDirectory() as directory:
                report = self.assess()
                report["status"] = "ready" if ready else "blocked"
                path = Path(directory) / "preflight.json"
                with patch.object(preflight, "assess", return_value=report) as assessment:
                    exit_code = preflight.main(["--source", self.fixture.source, "--target", self.fixture.target, "--output", str(path)])
                self.assertEqual(exit_code, 0 if ready else 2)
                self.assertEqual(json.loads(path.read_text()), report)
                assessment.assert_called_once_with(self.fixture.source, self.fixture.target)

    def test_cli_invalid_input_is_json_without_network_or_echoing_input(self):
        output = io.StringIO()
        with patch.object(preflight, "public_fetch") as fetch, contextlib.redirect_stdout(output):
            exit_code = preflight.main(["--source", "https://private.invalid/token=secret", "--target", self.fixture.target])
        self.assertEqual(exit_code, 2)
        self.assertEqual(json.loads(output.getvalue())["reasons"], [{"code": "stable_tag_required", "role": "source"}])
        self.assertNotIn("token=", output.getvalue())
        fetch.assert_not_called()

    def test_cli_output_failure_is_classified(self):
        output = io.StringIO()
        with patch.object(Path, "write_text", side_effect=OSError("private path secret")), contextlib.redirect_stderr(output):
            exit_code = preflight.main(["--source", "invalid", "--target", self.fixture.target, "--output", "/does-not-matter"])
        self.assertEqual(exit_code, 1)
        self.assertEqual(output.getvalue(), "Could not write the publication preflight report.\n")


class PublicTransportTest(unittest.TestCase):
    def test_reader_fetch_budgets_are_finite_and_cached_urls_do_not_refetch(self):
        for maximum in (preflight.MAX_FETCHES, preflight.MAX_HELPER_FETCHES):
            calls = []
            reader = preflight._Reader(lambda url: calls.append(url) or b'{}', maximum)
            first = preflight.API + '/git/blobs/' + 'a' * 40
            self.assertEqual(reader.raw(first), b'{}')
            self.assertEqual(reader.raw(first), b'{}')
            self.assertEqual(len(calls), 1)
            for index in range(maximum - 1):
                reader.raw(preflight.API + '/git/blobs/' + hashlib.sha1(str(index).encode()).hexdigest())
            with self.subTest(maximum=maximum), self.assertRaisesRegex(preflight.Refusal, 'public_fetch_limit'):
                reader.raw(preflight.API + '/git/blobs/' + 'f' * 40)
            self.assertEqual(len(calls), maximum, 'no request may be dispatched beyond the bound')

    def test_only_fixed_official_api_and_download_paths_are_accepted(self):
        allowed = (preflight.API + "/releases/tags/v1.2.0", preflight.API + "/git/ref/tags/v1.2.0",
                   preflight.API + "/git/tags/" + "a" * 40, preflight.API + "/git/commits/" + "a" * 40,
                   preflight.API + "/git/trees/" + "a" * 40 + "?recursive=1",
                   preflight.API + "/git/blobs/" + "a" * 40,
                   preflight.RELEASES + "/download/v1.2.0/release-manifest.json")
        for url in allowed:
            self.assertTrue(preflight._valid_url(url), url)
        for url in ("http://api.github.com/repos/adamgreenwell/wayfindr/releases/tags/v1.2.0",
                    preflight.API + "/contents/.env", preflight.API + "/releases/tags/v1.2.0?token=secret",
                    preflight.API + "/releases/tags/v1.2.0#fragment", preflight.API.replace("https://", "https://user:pass@") + "/releases/tags/v1.2.0",
                    preflight.API.replace("github.com", "github.com.evil.invalid") + "/releases/tags/v1.2.0",
                    preflight.API.replace("adamgreenwell", "other") + "/releases/tags/v1.2.0",
                    preflight.RELEASES + "/download/v1.2.0/install.sh"):
            with self.subTest(url=url), patch.object(urllib.request, "build_opener") as opener:
                with self.assertRaises(preflight.Refusal) as error:
                    preflight.public_fetch(url)
                self.assertEqual(error.exception.code, "public_url_refused")
                opener.assert_not_called()

    def test_release_asset_redirects_are_bounded_and_path_restricted(self):
        import time
        handler = preflight._Redirects(True, time.monotonic() + 10)
        request = urllib.request.Request(preflight.RELEASES + "/download/v1.2.0/release-manifest.json")
        allowed = "https://release-assets.githubusercontent.com/github-production-release-asset/123/12345678-1234-1234-1234-123456789abc?sp=r&sig=public-signed-redirect"
        for _ in range(3):
            self.assertEqual(handler.redirect_request(request, None, 302, "redirect", {}, allowed).full_url, allowed)
        with self.assertRaises(preflight.Refusal):
            handler.redirect_request(request, None, 302, "redirect", {}, allowed)
        for url in ("http://release-assets.githubusercontent.com/github-production-release-asset/123/12345678-1234-1234-1234-123456789abc",
                    "https://release-assets.githubusercontent.com/anything-else/file", "https://other.example/file",
                    allowed.replace("https://", "https://user:password@"), allowed + "#fragment"):
            with self.subTest(url=url):
                handler = preflight._Redirects(True, time.monotonic() + 10)
                with self.assertRaises(preflight.Refusal):
                    handler.redirect_request(request, None, 302, "redirect", {}, url)
        with self.assertRaises(preflight.Refusal):
            preflight._Redirects(False, time.monotonic() + 10).redirect_request(request, None, 302, "redirect", {}, allowed)

    def test_public_fetch_disables_ambient_proxy_and_sets_bounded_timeout_without_auth(self):
        class Response:
            status = 200
            headers = {"Content-Length": "2"}

            def __enter__(self):
                return self

            def __exit__(self, *_):
                pass

            def read1(self, maximum):
                raw, self.raw = self.raw[:maximum], self.raw[maximum:]
                return raw

            raw = b"{}"

        with patch.object(urllib.request, "build_opener") as builder:
            builder.return_value.open.return_value = Response()
            self.assertEqual(preflight.public_fetch(preflight.API + "/releases/tags/v1.2.0"), b"{}")
            self.assertEqual(builder.call_args.args[0].proxies, {})
            request = builder.return_value.open.call_args.args[0]
            self.assertNotIn("Authorization", request.headers)
            self.assertEqual(builder.return_value.open.call_args.kwargs["timeout"], preflight.FETCH_TIMEOUT)


if __name__ == "__main__":
    unittest.main()
