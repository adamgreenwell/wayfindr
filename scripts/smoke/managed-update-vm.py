#!/usr/bin/env python3
"""Published-artifact gates and bounded, explicitly disposable VM observations.

This qualification tool never enrolls, starts an update, reboots a VM, restores
data, or clears a maintenance hold. Its only fault is an explicit interruption
of the fixed enrolled helper service. Receipts are observations, not qualification.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import struct
import subprocess
import sys
import time
import uuid

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
STATE = Path('/var/lib/wayfindr-updater/qualification')
MARKER = STATE / 'disposable.json'
BOOT = Path('/proc/sys/kernel/random/boot_id')
UNIT = 'wayfindr-updater.service'
SERVICES = ('web', 'queue', 'backup-queue', 'scheduler', 'reverb')
CHECKPOINTS = ('target_download_intent', 'target_verified', 'data_protected',
               'migration_intent', 'migrations_verified', 'target_restart_intent',
               'target_services_started', 'runtime_verified', 'configuration_commit_intent',
               'configuration_committed', 'apply_release_intent')
SAFE_ENV = {'PATH': '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG': 'C'}


class Refusal(Exception):
    pass


def module(name):
    spec = importlib.util.spec_from_file_location('qualification_' + name, ROOT / 'scripts/self-host' / (name + '.py'))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def canonical_uuid(value):
    try:
        return isinstance(value, str) and str(uuid.UUID(value)) == value
    except ValueError:
        return False


def utc():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def read_json(path, maximum=2_000_000):
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > maximum:
            raise Refusal('receipt_invalid')
        return module('updater').strict_json(path.read_bytes(), 'receipt_invalid')
    except (OSError, ValueError):
        raise Refusal('receipt_invalid') from None


def write_new(path, value):
    """Exclusive private writes; never truncate another receipt or follow links."""
    raw = (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as output:
            output.write(raw)
            output.flush()
            os.fsync(output.fileno())
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    except OSError:
        raise Refusal('receipt_write_refused') from None


def run(command, *, timeout=30):
    try:
        result = subprocess.run(command, env=SAFE_ENV, stdin=subprocess.DEVNULL,
                                capture_output=True, timeout=timeout, check=False)
        if result.returncode or len(result.stdout) > 2_000_000:
            raise Refusal('command_failed')
        return result.stdout.decode('utf-8').strip()
    except (OSError, UnicodeError, subprocess.TimeoutExpired):
        raise Refusal('command_unsettled') from None


def require_host():
    if sys.platform != 'linux' or os.geteuid() != 0 or not Path('/run/systemd/system').is_dir():
        raise Refusal('dedicated_linux_vm_required')
    if Path('/.dockerenv').exists() or Path('/run/.containerenv').exists():
        raise Refusal('container_host_refused')
    try:
        result = subprocess.run(['/usr/bin/systemd-detect-virt', '--container', '--quiet'],
                                env=SAFE_ENV, stdin=subprocess.DEVNULL, capture_output=True, timeout=10)
        if result.returncode != 1:
            raise Refusal('container_host_refused')
        result = subprocess.run(['/usr/bin/systemd-detect-virt', '--vm', '--quiet'],
                                env=SAFE_ENV, stdin=subprocess.DEVNULL, capture_output=True, timeout=10)
        if result.returncode != 0:
            raise Refusal('virtual_machine_unverified')
    except (OSError, subprocess.TimeoutExpired):
        raise Refusal('host_isolation_unknown') from None


def boot_id():
    try:
        value = BOOT.read_text().strip()
    except OSError:
        raise Refusal('boot_identity_unavailable') from None
    if not canonical_uuid(value):
        raise Refusal('boot_identity_unavailable')
    return value


def rpc_status(api, config, operation_id):
    """Read actual daemon identity over its existing authenticated local protocol."""
    api.trusted(api.SOCKET, socket_node=True)
    payload = {'protocol': api.PROTOCOL, 'installation_id': config.installation_id,
               'nonce': uuid.uuid4().hex, 'issued_at': int(time.time()), 'action': 'status'}
    if operation_id is not None:
        payload['operation_id'] = operation_id
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
        connection.settimeout(5)
        connection.connect(str(api.SOCKET))
        _, uid, _ = struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        if uid != 0:
            raise Refusal('helper_identity_unverified')
        connection.sendall(api.envelope(payload, config.token, 'request'))
        raw = bytearray()
        deadline = time.monotonic() + 5
        while not raw.endswith(b'\n'):
            connection.settimeout(max(0.01, deadline - time.monotonic()))
            chunk = connection.recv(4096)
            if not chunk or len(raw) + len(chunk) > api.RESPONSE_MAX or b'\n' in chunk[:-1] or time.monotonic() > deadline:
                raise Refusal('helper_identity_unverified')
            raw.extend(chunk)
        response = api.unpack_envelope(bytes(raw), config.token, 'response')
        if (response.get('protocol') != api.PROTOCOL or response.get('installation_id') != config.installation_id
                or response.get('nonce') != payload['nonce'] or response.get('ok') is not True):
            raise Refusal('helper_identity_unverified')
        return response['result']


class Host:
    def __init__(self, installation_id):
        require_host()
        if not canonical_uuid(installation_id):
            raise Refusal('installation_mismatch')
        self.api = module('updater')
        self.config = self.api.Configuration.load(verify_install=False)
        if self.config.installation_id != installation_id:
            raise Refusal('installation_mismatch')
        self.installation_id = installation_id
        self.docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock',
                       '--config', '/etc/wayfindr-updater/docker']

    def marker(self):
        self.api.trusted(MARKER)
        if MARKER.stat().st_mode & 0o077:
            raise Refusal('disposable_marker_invalid')
        value = read_json(MARKER)
        expected = {'schema', 'purpose', 'installation_id', 'run_id', 'vm_kind', 'created_at',
                    'initial_boot_id', 'source', 'target'}
        if (set(value) != expected or type(value['schema']) is not int or value['schema'] != 1
                or value['purpose'] != 'disposable-update-qualification'
                or value['installation_id'] != self.installation_id
                or not canonical_uuid(value['run_id']) or not canonical_uuid(value['initial_boot_id'])
                or value['vm_kind'] != 'dedicated_vm'):
            raise Refusal('disposable_marker_invalid')
        # Reuse the fixed public declaration shape; this marker is an operator
        # attestation of isolation, never proof that the matrix has been run.
        for role in ('source', 'target'):
            identity = value[role]
            if (not isinstance(identity, dict) or not self.api.TAG.fullmatch(identity.get('tag', ''))
                    or not self.api.COMMIT.fullmatch(identity.get('commit', ''))
                    or not self.api.DIGEST.fullmatch(identity.get('image_digest', ''))):
                raise Refusal('disposable_marker_invalid')
        return value

    def observe(self, operation_id=None):
        if operation_id is not None and not canonical_uuid(operation_id):
            raise Refusal('operation_mismatch')
        journal = self.api.Journal(self.api.STATE_DIR / 'journal.json', self.installation_id)
        status = journal.status(operation_id)
        # Offline Journal.status labels VERSION from the observer's code. Never
        # attribute that value to the installed/running daemon.
        status['helper_version'] = None
        version_source = 'unverified_offline_journal'
        try:
            live = rpc_status(self.api, self.config, operation_id)
            if (set(live) != set(status) or live['installation_id'] != self.installation_id
                    or live['generation'] != status['generation']
                    or type(live['revision']) is not int or live['revision'] < status['revision']
                    or not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', live['helper_version'])):
                raise Refusal('helper_identity_unverified')
            # Control observations must use the entire newer authenticated
            # status. Keeping an earlier journal checkpoint while borrowing
            # only the daemon version could dispatch a fault after completion.
            status = live
            version_source = 'authenticated_rpc'
        except Exception:
            pass  # Keep the durable offline state, explicitly without helper identity proof.
        operation = status['operation']
        # The helper validated and redacted the journal; no environment, keys,
        # raw process arguments, Docker config, or customer content is emitted.
        return {'schema': 1, 'scope': 'vm_observation', 'qualification': False,
                'observed_at': utc(), 'boot_id': boot_id(), 'status': status,
                'observer_version': self.api.VERSION, 'helper_version_source': version_source,
                'runtime_checks': 'not_executed', 'operation_id': None if operation is None else operation['operation_id']}

    def services(self):
        install = Path(self.config.value['install_dir'])
        compose = self.docker + ['compose', '--project-name', 'wayfindr-self-hosting',
                  '--env-file', str(install / '.env'), '-f', str(install / 'compose.yml'),
                  '-f', str(install / 'compose.updater.yml')]
        records = {}
        for service in SERVICES:
            identifier = run(compose + ['ps', '--all', '--quiet', '--no-trunc', service])
            if not re.fullmatch(r'[0-9a-f]{64}', identifier):
                raise Refusal('service_identity_unknown')
            raw = run(self.docker + ['inspect', '--format',
                '{"id":{{json .Id}},"image":{{json .Image}},"running":{{json .State.Running}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"service":{{json (index .Config.Labels "com.docker.compose.service")}}}', identifier])
            record = self.api.strict_json(raw.encode(), 'service_identity_unknown')
            if (set(record) != {'id', 'image', 'running', 'project', 'service'} or record['id'] != identifier
                    or record['project'] != 'wayfindr-self-hosting' or record['service'] != service
                    or type(record['running']) is not bool or not self.api.DIGEST.fullmatch(record['image'])):
                raise Refusal('service_identity_unknown')
            records[service] = {key: record[key] for key in ('id', 'image', 'running')}
        return records


def bind_operation(observation, marker, operation_id, *, active=False):
    status = observation['status']
    operation = status['operation']
    if (status['installation_id'] != marker['installation_id'] or not operation
            or operation['operation_id'] != operation_id
            or (active and status['active_operation'] != operation_id)
            or operation['source'] != {'version': marker['source']['tag'][1:], 'commit': marker['source']['commit']}
            or operation['target'] != {'tag': marker['target']['tag'], 'version': marker['target']['tag'][1:],
                                     'commit': marker['target']['commit'], 'image_digest': marker['target']['image_digest']}
            or not canonical_uuid(operation['request_id'])
            or type(operation['revision']) is not int or operation['revision'] < 1
            or not isinstance(operation['plan_id'], str) or not re.fullmatch(r'[0-9a-f]{64}', operation['plan_id'])):
        raise Refusal('operation_mismatch')


def private_receipt(host, path):
    host.api.trusted(path.parent, directory=True)
    if path.parent.stat().st_mode & 0o077:
        raise Refusal('private_receipt_directory_required')


def wait_checkpoint(host, marker, operation_id, checkpoint, timeout):
    if checkpoint not in CHECKPOINTS or type(timeout) is not int or not 1 <= timeout <= 300:
        raise Refusal('checkpoint_invalid')
    deadline = time.monotonic() + timeout
    while True:
        observation = host.observe(operation_id)
        bind_operation(observation, marker, operation_id, active=True)
        operation = observation['status']['operation']
        if operation['phase'] in {'succeeded', 'failed_safe', 'cancelled', 'recovery_required', 'blocked'}:
            raise Refusal('checkpoint_not_observed')
        if operation['checkpoint'] == checkpoint:
            if observation['helper_version_source'] != 'authenticated_rpc':
                raise Refusal('helper_identity_unverified')
            return observation
        if time.monotonic() >= deadline:
            raise Refusal('checkpoint_not_observed')
        time.sleep(0.2)


def interrupt(host, operation_id, checkpoint, timeout, output):
    private_receipt(host, output)
    marker = host.marker()
    before = wait_checkpoint(host, marker, operation_id, checkpoint, timeout)
    receipt = {'schema': 1, 'scope': 'helper_interruption_intent', 'qualification': False,
               'run_id': marker['run_id'], 'installation_id': marker['installation_id'],
               'operation_id': operation_id, 'observed_checkpoint': checkpoint, 'before': before,
               'limitations': ['checkpoint_observation_is_not_an_atomic_pause', 'recovery_not_executed']}
    write_new(output, receipt)  # Durable intent precedes the one explicit fault.
    # Kill only the fixed enrolled service cgroup. Docker and the application
    # containers are not killed; a migration oneoff may still be running.
    run(['/usr/bin/systemctl', 'kill', '--kill-whom=all', '--signal=SIGKILL', UNIT])
    return receipt


def checkpoint_reboot(host, operation_id, output):
    private_receipt(host, output)
    marker = host.marker()
    before = host.observe(operation_id)
    bind_operation(before, marker, operation_id, active=True)
    receipt = {'schema': 1, 'scope': 'reboot_checkpoint', 'qualification': False,
               'run_id': marker['run_id'], 'installation_id': marker['installation_id'],
               'operation_id': operation_id, 'before': before,
               'limitations': ['reboot_not_executed', 'recovery_not_executed']}
    write_new(output, receipt)
    return receipt


def resume_reboot(host, checkpoint, output):
    private_receipt(host, output)
    marker = host.marker()
    host.api.trusted(checkpoint)
    if checkpoint.stat().st_mode & 0o077:
        raise Refusal('receipt_invalid')
    saved = read_json(checkpoint)
    expected = {'schema', 'scope', 'qualification', 'run_id', 'installation_id', 'operation_id', 'before', 'limitations'}
    if (set(saved) != expected or type(saved['schema']) is not int or saved['schema'] != 1
            or saved['scope'] != 'reboot_checkpoint' or saved['qualification'] is not False
            or saved['run_id'] != marker['run_id'] or saved['installation_id'] != marker['installation_id']
            or not canonical_uuid(saved['operation_id'])):
        raise Refusal('receipt_invalid')
    before = saved['before']
    bind_operation(before, marker, saved['operation_id'])
    after = host.observe(saved['operation_id'])
    bind_operation(after, marker, saved['operation_id'])
    old, new = before['status'], after['status']
    if (before['boot_id'] == after['boot_id'] or not canonical_uuid(before['boot_id'])
            or old['operation']['plan_id'] != new['operation']['plan_id']
            or old['operation']['request_id'] != new['operation']['request_id']
            or new['operation']['revision'] <= old['operation']['revision']
            or old['generation'] == new['generation'] or new['revision'] <= old['revision']):
        raise Refusal('reboot_not_reconciled')
    receipt = {'schema': 1, 'scope': 'reboot_observation', 'qualification': False,
               'run_id': marker['run_id'], 'installation_id': marker['installation_id'],
               'operation_id': saved['operation_id'], 'before': before, 'after': after,
               'limitations': ['recovery_not_executed', 'data_survival_not_verified', 'matrix_incomplete']}
    write_new(output, receipt)
    return receipt


def adopt(host, source, target, acknowledged):
    if acknowledged is not True:
        raise Refusal('explicit_disposable_acknowledgement_required')
    gate = module('update_vm_preflight').assess(source, target)
    if gate['status'] != 'ready':
        raise Refusal('published_artifacts_unavailable')
    host.config.verify_files()
    run(['/usr/bin/systemctl', 'is-active', '--quiet', UNIT])
    reference = host.config.value['image_reference']
    metadata = host.api.strict_json(run(host.docker + ['image', 'inspect', '--format',
        '{"id":{{json .Id}},"digests":{{json .RepoDigests}},"env":{{json .Config.Env}}}', reference]).encode(), 'source_identity_mismatch')
    if (set(metadata) != {'id', 'digests', 'env'} or not host.api.DIGEST.fullmatch(metadata['id'])
            or type(metadata['digests']) is not list or any(type(item) is not str for item in metadata['digests'])
            or ('ghcr.io/adamgreenwell/wayfindr@' + gate['source']['image_digest']) not in metadata['digests']
            or type(metadata['env']) is not list):
        raise Refusal('source_identity_mismatch')
    identity = {}
    for entry in metadata['env']:
        if not isinstance(entry, str) or '=' not in entry:
            raise Refusal('source_identity_mismatch')
        key, value = entry.split('=', 1)
        if key in {'WAYFINDR_VERSION', 'WAYFINDR_COMMIT'}:
            if key in identity:
                raise Refusal('source_identity_mismatch')
            identity[key] = value
    if (identity.get('WAYFINDR_VERSION', '').removeprefix('v') != gate['source']['tag'][1:]
            or identity.get('WAYFINDR_COMMIT') != gate['source']['commit']):
        raise Refusal('source_identity_mismatch')
    services = host.services()
    if (set(services) != set(SERVICES) or len({record['id'] for record in services.values()}) != len(SERVICES)
            or any(record['image'] != metadata['id'] or record['running'] is not True for record in services.values())):
        raise Refusal('source_identity_mismatch')
    observed = host.observe(None)
    if observed['status']['active_operation'] is not None:
        raise Refusal('operation_already_active')
    if (observed['helper_version_source'] != 'authenticated_rpc'
            or observed['status']['helper_version'] != gate['source']['helper_version']):
        raise Refusal('helper_identity_unverified')
    host.api.trusted(STATE.parent, directory=True)
    STATE.mkdir(mode=0o700, exist_ok=True)
    host.api.trusted(STATE, directory=True)
    if STATE.stat().st_mode & 0o077:
        raise Refusal('disposable_marker_invalid')
    marker = {'schema': 1, 'purpose': 'disposable-update-qualification',
              'installation_id': host.installation_id, 'run_id': str(uuid.uuid4()),
              'vm_kind': 'dedicated_vm', 'created_at': utc(), 'initial_boot_id': boot_id(),
              'source': gate['source'], 'target': gate['target']}
    write_new(MARKER, marker)
    return {'schema': 1, 'scope': 'operator_isolation_attestation', 'qualification': False,
            'run_id': marker['run_id'], 'installation_id': host.installation_id}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    gate = commands.add_parser('preflight', help='Read-only published release gate; no VM or Docker access.')
    gate.add_argument('--source', required=True)
    gate.add_argument('--target', required=True)
    gate.add_argument('--output', type=Path, required=True)
    gate.add_argument('--evidence-output', type=Path, help='Also write an honest blocked/not-run evidence scaffold.')
    for action in ('adopt', 'snapshot', 'interrupt-helper', 'checkpoint-reboot', 'resume-reboot'):
        item = commands.add_parser(action)
        item.add_argument('--installation-id', required=True)
        if action == 'adopt':
            item.add_argument('--source', required=True)
            item.add_argument('--target', required=True)
            item.add_argument('--ack-disposable', action='store_true')
        else:
            item.add_argument('--output', type=Path, required=True)
            if action == 'resume-reboot':
                item.add_argument('--checkpoint-file', type=Path, required=True)
            else:
                item.add_argument('--operation', required=True)
            if action == 'interrupt-helper':
                item.add_argument('--checkpoint', choices=CHECKPOINTS, required=True)
                item.add_argument('--timeout', type=int, default=60)
    args = parser.parse_args(argv)
    try:
        if args.action == 'preflight':
            result = module('update_vm_preflight').assess(args.source, args.target)
            write_new(args.output, result)
            if args.evidence_output:
                limitations = ['vm_not_provisioned', 'reboot_not_executed', 'restore_not_executed', 'matrix_incomplete']
                if result['status'] != 'ready':
                    unpublished = any(reason['code'] == 'helper_not_published' for reason in result['reasons'])
                    limitations.append('artifacts_unpublished' if unpublished else 'artifacts_unverified')
                report = module('update_vm_evidence').empty_report(
                    claim='blocked' if result['status'] != 'ready' else 'not_run', limitations=limitations)
                write_new(args.evidence_output, report)
            print(json.dumps(result, sort_keys=True))
            return 0 if result['status'] == 'ready' else 2
        host = Host(args.installation_id)
        if args.action == 'adopt':
            result = adopt(host, args.source, args.target, args.ack_disposable)
        elif args.action == 'snapshot':
            private_receipt(host, args.output)
            marker = host.marker()
            result = host.observe(args.operation)
            bind_operation(result, marker, args.operation)
            result['services'] = host.services()
            write_new(args.output, result)
        elif args.action == 'interrupt-helper':
            result = interrupt(host, args.operation, args.checkpoint, args.timeout, args.output)
        elif args.action == 'checkpoint-reboot':
            result = checkpoint_reboot(host, args.operation, args.output)
        else:
            result = resume_reboot(host, args.checkpoint_file, args.output)
        print(json.dumps(result, sort_keys=True))
        return 0  # A completed observation/action is not a qualified outcome.
    except Exception as failure:
        reason = str(failure) if isinstance(failure, Refusal) else 'observation_unavailable'
        print(json.dumps({'schema': 1, 'qualification': False, 'status': 'blocked', 'reason': reason}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
