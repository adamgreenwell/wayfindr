#!/usr/bin/env python3
"""Synthetic harness tests. No real VM, Docker, interruption, reboot or restore."""

import copy
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch
import uuid

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('vm_driver', ROOT / 'scripts/smoke/managed-update-vm.py')
VM = importlib.util.module_from_spec(spec)
spec.loader.exec_module(VM)
SOURCE = {'tag': 'v1.2.0', 'commit': 'a' * 40, 'image_digest': 'sha256:' + 'b' * 64}
TARGET = {'tag': 'v1.2.1', 'commit': 'c' * 40, 'image_digest': 'sha256:' + 'd' * 64}


class FixtureHost:
    def __init__(self):
        self.saved = {'installation_id': str(uuid.uuid4()), 'run_id': str(uuid.uuid4()),
                      'source': copy.deepcopy(SOURCE), 'target': copy.deepcopy(TARGET)}
        self.operation = str(uuid.uuid4())
        self.snapshot = {'schema': 1, 'scope': 'vm_observation', 'qualification': False,
            'boot_id': str(uuid.uuid4()), 'helper_version_source': 'authenticated_rpc',
            'status': {'installation_id': self.saved['installation_id'],
            'generation': str(uuid.uuid4()), 'revision': 10, 'active_operation': self.operation,
            'operation': {'operation_id': self.operation, 'phase': 'applying',
            'checkpoint': 'migration_intent', 'plan_id': 'e' * 64, 'request_id': str(uuid.uuid4()), 'revision': 8,
            'source': {'version': SOURCE['tag'][1:], 'commit': SOURCE['commit']},
            'target': {'tag': TARGET['tag'], 'version': TARGET['tag'][1:],
                       'commit': TARGET['commit'], 'image_digest': TARGET['image_digest']}}}}
        self.api = types.SimpleNamespace(trusted=lambda *_args, **_kwargs: None)

    def marker(self):
        return copy.deepcopy(self.saved)

    def observe(self, operation_id):
        return copy.deepcopy(self.snapshot)


class DriverTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.output = Path(self.directory.name) / 'receipt.json'
        self.host = FixtureHost()

    def test_private_exclusive_receipts_never_overwrite_or_follow_symlinks(self):
        VM.write_new(self.output, {'safe': True})
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
        with self.assertRaisesRegex(VM.Refusal, 'receipt_write_refused'):
            VM.write_new(self.output, {'safe': False})
        self.assertEqual(json.loads(self.output.read_text()), {'safe': True})
        alias = self.output.with_name('alias.json')
        alias.symlink_to(self.output)
        with self.assertRaisesRegex(VM.Refusal, 'receipt_write_refused'):
            VM.write_new(alias, {'safe': False})

    def test_duplicate_json_keys_are_refused(self):
        self.output.write_text('{"schema":1,"schema":2}')
        with self.assertRaises(Exception):
            VM.read_json(self.output)

    def test_non_linux_and_non_root_cannot_reach_any_host_command(self):
        for platform, uid in [('darwin', 0), ('linux', 1000)]:
            with self.subTest(platform=platform, uid=uid), patch.object(VM.sys, 'platform', platform), \
                    patch.object(VM.os, 'geteuid', return_value=uid), patch.object(VM.subprocess, 'run') as command:
                with self.assertRaisesRegex(VM.Refusal, 'dedicated_linux_vm_required'):
                    VM.require_host()
                command.assert_not_called()

    def test_container_host_isolation_refuses_detected_and_unknown_containers(self):
        with patch.object(VM.sys, 'platform', 'linux'), patch.object(VM.os, 'geteuid', return_value=0), \
                patch.object(Path, 'is_dir', return_value=True), patch.object(Path, 'exists', return_value=False):
            for code in [0, 2, 127]:
                with self.subTest(code=code), patch.object(VM.subprocess, 'run', return_value=types.SimpleNamespace(returncode=code)):
                    with self.assertRaisesRegex(VM.Refusal, 'container_host_refused'):
                        VM.require_host()

    def test_bare_metal_or_unknown_vm_is_refused(self):
        with patch.object(VM.sys, 'platform', 'linux'), patch.object(VM.os, 'geteuid', return_value=0), \
                patch.object(Path, 'is_dir', return_value=True), patch.object(Path, 'exists', return_value=False), \
                patch.object(VM.subprocess, 'run', side_effect=[types.SimpleNamespace(returncode=1), types.SimpleNamespace(returncode=1)]):
            with self.assertRaisesRegex(VM.Refusal, 'virtual_machine_unverified'):
                VM.require_host()

    def test_exact_checkpoint_intent_precedes_the_only_fixed_service_fault(self):
        calls = []
        def command(arguments):
            self.assertTrue(self.output.exists(), 'fault must never precede durable intent')
            value = json.loads(self.output.read_text())
            self.assertEqual(value['observed_checkpoint'], 'migration_intent')
            self.assertFalse(value['qualification'])
            calls.append(arguments)
        with patch.object(VM, 'run', side_effect=command):
            result = VM.interrupt(self.host, self.host.operation, 'migration_intent', 1, self.output)
        self.assertEqual(calls, [['/usr/bin/systemctl', 'kill', '--kill-whom=all', '--signal=SIGKILL', 'wayfindr-updater.service']])
        self.assertIn('checkpoint_observation_is_not_an_atomic_pause', result['limitations'])
        self.assertIn('recovery_not_executed', result['limitations'])

    def test_fault_refuses_changed_installation_operation_source_target_and_plan(self):
        changes = [lambda status: status.update(installation_id=str(uuid.uuid4())),
                   lambda status: status.update(active_operation=str(uuid.uuid4())),
                   lambda status: status['operation'].update(operation_id=str(uuid.uuid4())),
                   lambda status: status['operation']['source'].update(commit='f' * 40),
                   lambda status: status['operation']['target'].update(image_digest='sha256:' + 'f' * 64),
                   lambda status: status['operation'].update(plan_id=None)]
        for change in changes:
            host = FixtureHost()
            change(host.snapshot['status'])
            with self.subTest(change=change), patch.object(VM, 'run') as command:
                with self.assertRaisesRegex(VM.Refusal, 'operation_mismatch'):
                    VM.interrupt(host, host.operation, 'migration_intent', 1, self.output)
                command.assert_not_called()
                self.assertFalse(self.output.exists())

    def test_missing_checkpoint_never_injects_a_different_fault(self):
        with patch.object(VM.time, 'monotonic', side_effect=[0, 2]), patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'checkpoint_not_observed'):
                VM.interrupt(self.host, self.host.operation, 'target_verified', 1, self.output)
            command.assert_not_called()
            self.assertFalse(self.output.exists())

    def test_terminal_or_recovery_state_never_injects_a_late_fault(self):
        for phase in ['succeeded', 'failed_safe', 'cancelled', 'recovery_required', 'blocked']:
            host = FixtureHost()
            host.snapshot['status']['operation']['phase'] = phase
            with self.subTest(phase=phase), patch.object(VM, 'run') as command:
                with self.assertRaisesRegex(VM.Refusal, 'checkpoint_not_observed'):
                    VM.interrupt(host, host.operation, 'migration_intent', 1, self.output)
                command.assert_not_called()

    def test_receipt_collision_prevents_the_fault(self):
        VM.write_new(self.output, {'prior': True})
        with patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'receipt_write_refused'):
                VM.interrupt(self.host, self.host.operation, 'migration_intent', 1, self.output)
            command.assert_not_called()

    def test_directory_fsync_failure_prevents_fault_dispatch(self):
        with patch.object(VM.os, 'fsync', side_effect=[None, OSError('fixture')]) as sync, patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'receipt_write_refused'):
                VM.interrupt(self.host, self.host.operation, 'migration_intent', 1, self.output)
            self.assertEqual(sync.call_count, 2, 'receipt bytes and containing directory must both be synced')
            command.assert_not_called()

    def test_vm_receipt_parent_must_be_trusted_and_private(self):
        self.output.parent.chmod(0o755)
        with patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'private_receipt_directory_required'):
                VM.checkpoint_reboot(self.host, self.host.operation, self.output)
            command.assert_not_called()
            self.assertFalse(self.output.exists())
        self.output.parent.chmod(0o700)
        with patch.object(self.host.api, 'trusted', side_effect=VM.Refusal('untrusted_parent')):
            with self.assertRaisesRegex(VM.Refusal, 'untrusted_parent'):
                VM.checkpoint_reboot(self.host, self.host.operation, self.output)

    def test_unsettled_fault_keeps_intent_and_never_recovers_or_reboots(self):
        with patch.object(VM, 'run', side_effect=VM.Refusal('command_unsettled')) as command:
            with self.assertRaisesRegex(VM.Refusal, 'command_unsettled'):
                VM.interrupt(self.host, self.host.operation, 'migration_intent', 1, self.output)
        self.assertEqual(command.call_count, 1)
        self.assertEqual(json.loads(self.output.read_text())['scope'], 'helper_interruption_intent')

    def test_unverified_offline_helper_identity_cannot_trigger_a_fault(self):
        self.host.snapshot['helper_version_source'] = 'unverified_offline_journal'
        with patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'helper_identity_unverified'):
                VM.interrupt(self.host, self.host.operation, 'migration_intent', 1, self.output)
            command.assert_not_called()

    def test_reboot_checkpoint_only_records_and_cannot_reboot(self):
        with patch.object(VM, 'run') as command:
            result = VM.checkpoint_reboot(self.host, self.host.operation, self.output)
            command.assert_not_called()
        self.assertIn('reboot_not_executed', result['limitations'])
        self.assertFalse(result['qualification'])

    def resumed(self, *, boot=True, generation=True, revision=True):
        checkpoint = Path(self.directory.name) / 'before.json'
        VM.checkpoint_reboot(self.host, self.host.operation, checkpoint)
        if boot:
            self.host.snapshot['boot_id'] = str(uuid.uuid4())
        if generation:
            self.host.snapshot['status']['generation'] = str(uuid.uuid4())
        if revision:
            self.host.snapshot['status']['revision'] += 2
            self.host.snapshot['status']['operation']['revision'] += 2
        self.host.snapshot['status']['operation']['phase'] = 'recovery_required'
        return checkpoint

    def test_reboot_requires_changed_boot_generation_and_advanced_journal(self):
        for field in ['boot', 'generation', 'revision']:
            self.host = FixtureHost()
            with self.subTest(field=field):
                path = self.resumed(**{field: False})
                with self.assertRaisesRegex(VM.Refusal, 'reboot_not_reconciled'):
                    VM.resume_reboot(self.host, path, self.output)
                self.assertFalse(self.output.exists())
                path.unlink()

    def test_reboot_cannot_adopt_a_different_run_installation_operation_or_plan(self):
        for field in ['run_id', 'installation_id', 'operation_id', 'plan_id']:
            self.host = FixtureHost()
            path = self.resumed()
            saved = json.loads(path.read_text())
            if field == 'plan_id':
                saved['before']['status']['operation']['plan_id'] = 'f' * 64
            else:
                saved[field] = str(uuid.uuid4())
            path.write_text(json.dumps(saved))
            with self.subTest(field=field), self.assertRaisesRegex(VM.Refusal, 'receipt_invalid|operation_mismatch|reboot_not_reconciled'):
                VM.resume_reboot(self.host, path, self.output)
            path.unlink()

    def test_real_changed_boot_observation_still_does_not_claim_recovery_or_qualification(self):
        path = self.resumed()
        with patch.object(VM, 'run') as command:
            result = VM.resume_reboot(self.host, path, self.output)
            command.assert_not_called()
        self.assertFalse(result['qualification'])
        self.assertEqual(result['after']['status']['operation']['phase'], 'recovery_required')
        self.assertIn('data_survival_not_verified', result['limitations'])

    def test_reboot_requires_exact_request_and_advanced_operation_revision(self):
        for field in ['request_id', 'revision']:
            self.host = FixtureHost()
            path = self.resumed()
            if field == 'request_id':
                self.host.snapshot['status']['operation'][field] = str(uuid.uuid4())
            else:
                self.host.snapshot['status']['operation'][field] = 8
            with self.subTest(field=field), self.assertRaisesRegex(VM.Refusal, 'reboot_not_reconciled'):
                VM.resume_reboot(self.host, path, self.output)
            path.unlink()

    def test_offline_observer_version_is_never_reported_as_running_helper_version(self):
        host = object.__new__(VM.Host)
        host.installation_id = self.host.saved['installation_id']
        host.config = types.SimpleNamespace()
        raw = copy.deepcopy(self.host.snapshot['status'])
        api = types.SimpleNamespace(VERSION='9.9.9', STATE_DIR=Path('/var/lib/wayfindr-updater'),
              Journal=lambda *_args: types.SimpleNamespace(status=lambda *_args: copy.deepcopy(raw)))
        host.api = api
        live = {**copy.deepcopy(raw), 'helper_version': '0.4.0'}
        with patch.object(VM, 'boot_id', return_value=self.host.snapshot['boot_id']), patch.object(VM, 'rpc_status', return_value=live):
            observed = host.observe(self.host.operation)
        self.assertEqual(observed['status']['helper_version'], '0.4.0')
        self.assertEqual(observed['observer_version'], '9.9.9')
        self.assertEqual(observed['helper_version_source'], 'authenticated_rpc')
        with patch.object(VM, 'boot_id', return_value=self.host.snapshot['boot_id']), patch.object(VM, 'rpc_status', side_effect=VM.Refusal('offline')):
            observed = host.observe(self.host.operation)
        self.assertIsNone(observed['status']['helper_version'])
        self.assertEqual(observed['helper_version_source'], 'unverified_offline_journal')

    def test_newer_live_terminal_status_replaces_offline_checkpoint_before_fault(self):
        host = object.__new__(VM.Host)
        host.installation_id = self.host.saved['installation_id']
        host.config = types.SimpleNamespace()
        raw = copy.deepcopy(self.host.snapshot['status'])
        host.api = types.SimpleNamespace(VERSION='9.9.9', STATE_DIR=Path('/var/lib/wayfindr-updater'),
            trusted=lambda *_args, **_kwargs: None,
            Journal=lambda *_args: types.SimpleNamespace(status=lambda *_args: copy.deepcopy(raw)))
        host.marker = self.host.marker
        live = {**copy.deepcopy(raw), 'helper_version': '0.4.0', 'revision': raw['revision'] + 1}
        live['operation']['phase'] = 'succeeded'
        live['operation']['revision'] += 1
        with patch.object(VM, 'boot_id', return_value=self.host.snapshot['boot_id']), \
                patch.object(VM, 'rpc_status', return_value=live), patch.object(VM, 'run') as command:
            observed = host.observe(self.host.operation)
            self.assertEqual(observed['status']['operation']['phase'], 'succeeded')
            self.assertEqual(observed['status']['revision'], live['revision'])
            with self.assertRaisesRegex(VM.Refusal, 'checkpoint_not_observed'):
                VM.interrupt(host, self.host.operation, 'migration_intent', 1, self.output)
            command.assert_not_called()
            self.assertFalse(self.output.exists())

    def adoption(self):
        host = types.SimpleNamespace(api=VM.module('updater'), installation_id=self.host.saved['installation_id'],
                docker=['/usr/bin/docker'], config=types.SimpleNamespace(value={'image_reference': 'ghcr.io/adamgreenwell/wayfindr:1.2.0'}, verify_files=lambda: None))
        host.api.trusted = lambda *_args, **_kwargs: None
        metadata = {'id': 'sha256:' + 'f' * 64, 'digests': ['ghcr.io/adamgreenwell/wayfindr@' + SOURCE['image_digest']],
                    'env': ['WAYFINDR_VERSION=1.2.0', 'WAYFINDR_COMMIT=' + SOURCE['commit']]}
        services = {service: {'id': format(index + 1, '064x'), 'image': metadata['id'], 'running': True} for index, service in enumerate(VM.SERVICES)}
        observation = {'helper_version_source': 'authenticated_rpc', 'status': {'active_operation': None, 'helper_version': '0.4.0'}}
        host.services = lambda: copy.deepcopy(services)
        host.observe = lambda *_args: copy.deepcopy(observation)
        gate = {'status': 'ready', 'source': {**SOURCE, 'helper_version': '0.4.0'}, 'target': TARGET}
        return host, metadata, services, observation, gate

    def test_adoption_requires_running_source_not_a_matching_cached_image(self):
        mutations = [lambda m, s, o: s['web'].update(image='sha256:' + 'e' * 64),
                     lambda m, s, o: s['queue'].update(running=False),
                     lambda m, s, o: s['queue'].update(id=s['web']['id']),
                     lambda m, s, o: m.update(env=['WAYFINDR_VERSION=1.2.0', 'WAYFINDR_COMMIT=' + 'e' * 40]),
                     lambda m, s, o: m.update(digests={'fake': m['digests'][0]}),
                     lambda m, s, o: o['status'].update(active_operation=str(uuid.uuid4())),
                     lambda m, s, o: o.update(helper_version_source='unverified_offline_journal')]
        for mutation in mutations:
            host, metadata, services, observation, gate = self.adoption()
            mutation(metadata, services, observation)
            with self.subTest(mutation=mutation), patch.object(VM, 'module', return_value=types.SimpleNamespace(assess=lambda *_args: gate)), \
                    patch.object(VM, 'run', side_effect=lambda command: '' if command[0] == '/usr/bin/systemctl' else json.dumps(metadata)), \
                    patch.object(VM, 'write_new') as write:
                with self.assertRaisesRegex(VM.Refusal, 'source_identity_mismatch|operation_already_active|helper_identity_unverified'):
                    VM.adopt(host, 'v1.2.0', 'v1.2.1', True)
                write.assert_not_called()

    def test_adoption_requires_ack_and_ready_publications_before_host_writes(self):
        host, metadata, services, observation, gate = self.adoption()
        with patch.object(VM, 'module') as dependency:
            with self.assertRaisesRegex(VM.Refusal, 'explicit_disposable_acknowledgement_required'):
                VM.adopt(host, 'v1.2.0', 'v1.2.1', False)
            dependency.assert_not_called()
        gate['status'] = 'blocked'
        with patch.object(VM, 'module', return_value=types.SimpleNamespace(assess=lambda *_args: gate)), patch.object(VM, 'run') as command:
            with self.assertRaisesRegex(VM.Refusal, 'published_artifacts_unavailable'):
                VM.adopt(host, 'v1.2.0', 'v1.2.1', True)
            command.assert_not_called()

    def test_valid_disposable_adoption_remains_an_operator_attestation(self):
        host, metadata, services, observation, gate = self.adoption()
        directory = self.output.parent / 'private-state'
        with patch.object(VM, 'STATE', directory), patch.object(VM, 'MARKER', directory / 'disposable.json'), \
                patch.object(VM, 'boot_id', return_value=str(uuid.uuid4())), \
                patch.object(VM, 'module', return_value=types.SimpleNamespace(assess=lambda *_args: gate)), \
                patch.object(VM, 'run', side_effect=lambda command: '' if command[0] == '/usr/bin/systemctl' else json.dumps(metadata)):
            result = VM.adopt(host, 'v1.2.0', 'v1.2.1', True)
        self.assertFalse(result['qualification'])
        self.assertEqual(result['scope'], 'operator_isolation_attestation')
        self.assertEqual(directory.stat().st_mode & 0o777, 0o700)

    def test_running_helper_identity_uses_authenticated_status_nonce_and_root_peer(self):
        api = VM.module('updater')
        api.trusted = lambda *_args, **_kwargs: None
        config = types.SimpleNamespace(installation_id=self.host.saved['installation_id'], token='a' * 64)
        sent = []
        class Connection:
            def __enter__(self): return self
            def __exit__(self, *_args): return None
            def settimeout(self, _value): pass
            def connect(self, _path): pass
            def getsockopt(self, *_args): return VM.struct.pack('3i', 123, 0, 0)
            def sendall(self, raw):
                self.request = api.unpack_envelope(raw, config.token, 'request')
                sent.append(self.request)
            def recv(self, _maximum):
                return api.envelope({'protocol': 1, 'installation_id': config.installation_id,
                    'nonce': self.request['nonce'], 'ok': True, 'result': {'helper_version': '0.4.0'}}, config.token, 'response')
        with patch.object(VM.socket, 'SO_PEERCRED', 17, create=True), patch.object(VM.socket, 'socket', return_value=Connection()):
            result = VM.rpc_status(api, config, None)
        self.assertEqual(result, {'helper_version': '0.4.0'})
        self.assertEqual(sent[0]['action'], 'status')
        self.assertNotIn('operation_id', sent[0])
        with patch.object(VM.socket, 'SO_PEERCRED', 17, create=True), patch.object(Connection, 'getsockopt', return_value=VM.struct.pack('3i', 123, 1000, 1000)), \
                patch.object(VM.socket, 'socket', return_value=Connection()):
            with self.assertRaisesRegex(VM.Refusal, 'helper_identity_unverified'):
                VM.rpc_status(api, config, self.host.operation)
        self.assertEqual(len(sent), 1, 'non-root peer must never receive an authenticated request')
        def wrong_nonce(connection, _maximum):
            return api.envelope({'protocol': 1, 'installation_id': config.installation_id,
                'nonce': '0' * 32, 'ok': True, 'result': {'helper_version': '0.4.0'}}, config.token, 'response')
        with patch.object(VM.socket, 'SO_PEERCRED', 17, create=True), patch.object(Connection, 'recv', wrong_nonce), \
                patch.object(VM.socket, 'socket', return_value=Connection()):
            with self.assertRaisesRegex(VM.Refusal, 'helper_identity_unverified'):
                VM.rpc_status(api, config, self.host.operation)

    def test_source_copy_mutations_fail_the_named_durability_and_binding_assertions(self):
        source = Path(VM.__file__).read_text()
        changes = [
            ('            os.fsync(directory)', '            pass',
             'test_directory_fsync_failure_prevents_fault_dispatch', 'Refusal not raised'),
            ("or operation['source'] != {'version': marker['source']['tag'][1:], 'commit': marker['source']['commit']}",
             'or False', 'test_fault_refuses_changed_installation_operation_source_target_and_plan', 'Refusal not raised'),
        ]
        for old, new, name, expected in changes:
            with self.subTest(guard=name):
                self.assertIn(old, source)
                mutant = types.ModuleType('vm_driver_mutant')
                mutant.__file__ = VM.__file__
                exec(compile(source.replace(old, new, 1), VM.__file__, 'exec'), mutant.__dict__)
                output = io.StringIO()
                with patch.object(sys.modules[__name__], 'VM', mutant):
                    result = unittest.TextTestRunner(stream=output).run(DriverTests(name))
                self.assertFalse(result.wasSuccessful(), 'source-copy guard mutation must fail')
                self.assertTrue(result.failures, 'mutation must fail an assertion, not a parse/import error')
                self.assertIn(expected, output.getvalue())

    def test_untrusted_reboot_checkpoint_is_refused_before_reading_host_status(self):
        path = self.resumed()
        path.chmod(0o644)
        with self.assertRaisesRegex(VM.Refusal, 'receipt_invalid'):
            VM.resume_reboot(self.host, path, self.output)

    def test_service_capture_uses_canonical_project_and_both_enrolled_compose_files(self):
        api = VM.module('updater')
        host = object.__new__(VM.Host)
        host.api = api
        host.config = types.SimpleNamespace(value={'install_dir': '/opt/wayfindr'})
        host.docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', '--config', '/etc/wayfindr-updater/docker']
        calls = []
        def command(arguments):
            calls.append(arguments)
            if 'compose' in arguments:
                self.assertIn('wayfindr-self-hosting', arguments)
                self.assertIn('/opt/wayfindr/compose.yml', arguments)
                self.assertIn('/opt/wayfindr/compose.updater.yml', arguments)
                return 'a' * 64
            service = VM.SERVICES[(len(calls) - 1) // 2]
            return json.dumps({'id': 'a' * 64, 'image': 'sha256:' + 'b' * 64, 'running': True,
                               'project': 'wayfindr-self-hosting', 'service': service})
        with patch.object(VM, 'run', side_effect=command):
            result = host.services()
        self.assertEqual(set(result), set(VM.SERVICES))
        self.assertEqual(len(calls), 10)
        self.assertEqual(set(result['web']), {'id', 'image', 'running'})

    def test_no_vm_access_or_mutation_on_read_only_preflight(self):
        gate = {'status': 'blocked', 'qualification': False, 'reasons': [{'code': 'helper_not_published'}]}
        original = VM.module
        def dependency(name):
            if name == 'update_vm_preflight':
                return types.SimpleNamespace(assess=lambda *_args: gate)
            return original(name)
        evidence = self.output.with_name('evidence.json')
        with patch.object(VM, 'module', side_effect=dependency), patch.object(VM, 'Host') as host, \
                patch.object(VM, 'run') as command, patch('sys.stdout', new_callable=io.StringIO):
            code = VM.main(['preflight', '--source', 'v1.1.0', '--target', 'v1.1.1',
                            '--output', str(self.output), '--evidence-output', str(evidence)])
        self.assertEqual(code, 2)
        host.assert_not_called()
        command.assert_not_called()
        report = VM.module('update_vm_evidence').validate_report(json.loads(evidence.read_text()))
        self.assertEqual(report['status'], 'blocked')
        self.assertFalse(report['qualified'])

    def test_generic_failure_never_prints_secret_exception_text(self):
        with patch.object(VM, 'Host', side_effect=ValueError('secret-value')), patch('sys.stdout', new_callable=io.StringIO) as output:
            code = VM.main(['snapshot', '--installation-id', str(uuid.uuid4()), '--operation', str(uuid.uuid4()), '--output', str(self.output)])
        self.assertEqual(code, 1)
        self.assertNotIn('secret-value', output.getvalue())
        self.assertIn('observation_unavailable', output.getvalue())


if __name__ == '__main__':
    unittest.main()
