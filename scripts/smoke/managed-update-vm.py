#!/usr/bin/env python3
"""Published-artifact gates and bounded, explicitly disposable VM observations.

This qualification tool never enrolls, starts an update, reboots a VM, restores
data, or clears a maintenance hold. Its only fault is an explicit interruption
of the fixed enrolled helper service. Receipts are observations, not qualification.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
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
PROC = Path('/proc')
UNIT = 'wayfindr-updater.service'
SERVICES = ('web', 'queue', 'backup-queue', 'scheduler', 'reverb')
PREPARATION_CHECKPOINTS = {'accepted': 'accepted', 'prepare_started': 'preparing'}
PROTECTION_CHECKPOINTS = ('protection_started', 'fenced', 'drained', 'backup_verified', 'services_resumed', 'protection_released')
CHECKPOINTS = (*PREPARATION_CHECKPOINTS, *PROTECTION_CHECKPOINTS, 'target_download_intent', 'target_verified', 'data_protected',
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


def rpc_status(api, config, operation_id, *, expected_pid=None):
    """Read actual daemon identity over its existing authenticated local protocol."""
    api.trusted(api.SOCKET, socket_node=True)
    payload = {'protocol': api.PROTOCOL, 'installation_id': config.installation_id,
               'nonce': uuid.uuid4().hex, 'issued_at': int(time.time()), 'action': 'status'}
    if operation_id is not None:
        payload['operation_id'] = operation_id
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
        connection.settimeout(5)
        connection.connect(str(api.SOCKET))
        pid, uid, _ = struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        if uid != 0 or (expected_pid is not None and pid != expected_pid):
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
        if (type(response.get('protocol')) is not int or response['protocol'] != api.PROTOCOL
                or response.get('installation_id') != config.installation_id
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
                    'initial_boot_id', 'source', 'target', 'helper'}
        if (set(value) != expected or type(value['schema']) is not int or value['schema'] != 2
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
        verify_helper_generation(self, value['helper'])
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
            or not canonical_uuid(operation_id) or operation['operation_id'] != operation_id
            or (active and status['active_operation'] != operation_id)
            or (active and (not canonical_uuid(status['generation'])
                            or operation['executor_generation'] != status['generation']))
            or (active and 'helper' in marker and (status['helper_version'] != marker['helper']['selected']['helper_version']
                    or operation['executor_version'] != marker['helper']['selected']['helper_version']))
            or operation['release_tag'] != marker['target']['tag']
            or not canonical_uuid(operation['request_id'])
            or type(operation['revision']) is not int or operation['revision'] < 1):
        raise Refusal('operation_mismatch')
    if operation['checkpoint'] in PREPARATION_CHECKPOINTS:
        # These real journal checkpoints precede plan/image resolution. Never
        # invent those bindings or accept a partially populated/mutating state.
        allowed = {PREPARATION_CHECKPOINTS[operation['checkpoint']]}
        if not active:
            allowed |= {'reconciliation_required', 'blocked'}
        if (operation['phase'] not in allowed
                or any(operation[key] is not None for key in ('source', 'target', 'plan_id'))
                or operation['mutation_started'] is not False or 'protection' in operation or 'apply' in operation):
            raise Refusal('operation_mismatch')
        return 'requested_release'
    if (operation['source'] != {'version': marker['source']['tag'][1:], 'commit': marker['source']['commit']}
            or operation['target'] != {'tag': marker['target']['tag'], 'version': marker['target']['tag'][1:],
                                     'commit': marker['target']['commit'], 'image_digest': marker['target']['image_digest']}
            or not isinstance(operation['plan_id'], str) or not re.fullmatch(r'[0-9a-f]{64}', operation['plan_id'])):
        raise Refusal('operation_mismatch')
    return 'resolved_plan'


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
            if (checkpoint in PROTECTION_CHECKPOINTS
                    and (operation['phase'] != 'protecting' or not isinstance(operation.get('protection'), dict))):
                raise Refusal('operation_mismatch')
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
               'binding': bind_operation(before, marker, operation_id, active=True),
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
            or (old['operation']['plan_id'] is not None and old['operation']['plan_id'] != new['operation']['plan_id'])
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


def helper_upgrade_api(binding):
    """Use only the local reviewed CLI, never import downloaded release code."""
    if binding['transaction_id'] is not None:
        expected = binding['selected']['upgrade_cli_sha256']
        raw = (ROOT / 'scripts/self-host/upgrade-updater.py').read_bytes()
        if expected is None or hashlib.sha256(raw).hexdigest() != expected:
            raise Refusal('helper_provenance_unverified')
    return module('upgrade-updater')


def no_helper_transaction(upgrade):
    for path in (upgrade.TRANSACTION, upgrade.STOP, upgrade.GATE_TEMP,
                 upgrade.STATE / '.helper-upgrade.next', upgrade.STATE / '.helper-upgrade-stop.next'):
        if upgrade.exists(path):
            raise Refusal('helper_upgrade_pending')


def verify_helper_generation(host, binding):
    """Revalidate the persisted public-byte binding before every VM control read."""
    if (not isinstance(binding, dict) or set(binding) != {'source', 'selected', 'transaction_id', 'receipt_sha256'}
            or not isinstance(binding['source'], dict) or not isinstance(binding['selected'], dict)):
        raise Refusal('helper_provenance_unverified')
    source, selected = binding['source'], binding['selected']
    for distribution in (source, selected):
        expected = {'tag', 'commit', 'source_tree_sha', 'schema', 'helper_version', 'protocol', 'files',
                    'bundle_sha256', 'provenance_files', 'upgrade_cli_sha256'}
        if (set(distribution) != expected or type(distribution['schema']) is not int or distribution['schema'] != 1
                or not host.api.TAG.fullmatch(distribution['tag']) or not host.api.COMMIT.fullmatch(distribution['commit'])
                or not host.api.COMMIT.fullmatch(distribution['source_tree_sha'])
                or not isinstance(distribution['files'], dict) or set(distribution['files']) != {
                    'updater.py', 'update_protection.py', 'update_apply.py', 'update_artifacts.py', 'protection_archive.py'}
                or any(not isinstance(sha, str) or not re.fullmatch(r'[0-9a-f]{64}', sha) for sha in distribution['files'].values())
                or type(distribution['protocol']) is not int or distribution['protocol'] != 1):
            raise Refusal('helper_provenance_unverified')
        declaration = {key: distribution[key] for key in ('schema', 'helper_version', 'protocol', 'files')}
        raw = (json.dumps(declaration, sort_keys=True, separators=(',', ':')) + '\n').encode()
        if hashlib.sha256(raw).hexdigest() != distribution['bundle_sha256']:
            raise Refusal('helper_provenance_unverified')
    upgrade = helper_upgrade_api(binding)
    no_helper_transaction(upgrade)
    if selected['protocol'] != host.api.PROTOCOL or selected['helper_version'] not in {'0.4.0', '0.5.0'}:
        raise Refusal('helper_provenance_unverified')
    if upgrade.code_hashes(upgrade.CODE) != selected['files']:
        raise Refusal('helper_provenance_unverified')
    transaction = binding['transaction_id']
    if transaction is None:
        if source != selected or binding['receipt_sha256'] is not None:
            raise Refusal('helper_provenance_unverified')
    else:
        if (not canonical_uuid(transaction) or source['helper_version'] != '0.4.0'
                or selected['helper_version'] != '0.5.0' or source['protocol'] != 1
                or upgrade.PUBLISHED != source['files']):
            raise Refusal('helper_provenance_unverified')
        upgrade.trusted(upgrade.RECEIPTS, directory=True)
        if upgrade.RECEIPTS.stat().st_mode & 0o777 != 0o700 or upgrade.RECEIPTS.stat().st_gid != 0:
            raise Refusal('helper_provenance_unverified')
        receipt_path = upgrade.RECEIPTS / (transaction + '.json')
        upgrade.private(receipt_path)
        receipt = read_json(receipt_path)
        expected = {'schema', 'transaction_id', 'installation_id', 'from_helper_version', 'helper_version',
                    'protocol', 'bundle_sha256', 'generation', 'preserved', 'application_changed', 'retained_old_code'}
        retained = upgrade.CODE.parent / ('.wayfindr-updater-generation-' + transaction)
        if (set(receipt) != expected or type(receipt['schema']) is not int or receipt['schema'] != 1
                or receipt['transaction_id'] != transaction or receipt['installation_id'] != host.installation_id
                or receipt['from_helper_version'] != source['helper_version']
                or receipt['helper_version'] != selected['helper_version'] or type(receipt['protocol']) is not int
                or receipt['protocol'] != selected['protocol'] or receipt['bundle_sha256'] != selected['bundle_sha256']
                or not canonical_uuid(receipt['generation']) or receipt['preserved'] is not True
                or receipt['application_changed'] is not False or receipt['retained_old_code'] != str(retained)
                or hashlib.sha256(receipt_path.read_bytes()).hexdigest() != binding['receipt_sha256']
                or upgrade.code_hashes(retained) != source['files']
                or (upgrade.CODE.stat().st_mode & 0o777) != (retained.stat().st_mode & 0o777)):
            raise Refusal('helper_provenance_unverified')
        upgrade.owned_gate()
        if not upgrade.exists(upgrade.DROPIN) or not upgrade.condition_loaded():
            raise Refusal('helper_provenance_unverified')
    no_helper_transaction(upgrade)
    return upgrade


def helper_provenance(host, gate, observed, helper_release, transaction):
    source, selected = gate['helpers']['source'], gate['helpers']['selected']
    if (observed['helper_version_source'] != 'authenticated_rpc'
            or observed['status']['helper_version'] != selected['helper_version']
            or (transaction is not None and helper_release is None)
            or (source != selected and transaction is None)):
        raise Refusal('helper_identity_unverified')
    binding = {'source': source, 'selected': selected, 'transaction_id': transaction, 'receipt_sha256': None}
    upgrade = helper_upgrade_api(binding)
    no_helper_transaction(upgrade)
    if transaction is not None:
        if not canonical_uuid(transaction):
            raise Refusal('helper_provenance_unverified')
        receipt = upgrade.RECEIPTS / (transaction + '.json')
        upgrade.private(receipt)
        binding['receipt_sha256'] = hashlib.sha256(receipt.read_bytes()).hexdigest()
    upgrade = verify_helper_generation(host, binding)
    # The source installer/compose and canonical enrollment survive a helper
    # replacement. Do not claim that the old application's tag shipped 0.5.
    for path, config_key in (('scripts/self-host/install.sh', 'installer_sha256'),
                             ('docker/self-hosting/compose.yml', 'compose_sha256')):
        if host.config.value[config_key] != source['provenance_files'][path]:
            raise Refusal('helper_provenance_unverified')
    for path in ('docker/self-hosting/wayfindr-updater.service', 'docker/self-hosting/compose.updater.yml'):
        if hashlib.sha256((ROOT / path).read_bytes()).hexdigest() != source['provenance_files'][path]:
            raise Refusal('helper_provenance_unverified')
    if (upgrade.UNIT_SHA != source['provenance_files']['docker/self-hosting/wayfindr-updater.service']
            or upgrade.TMPFILES_SHA != source['provenance_files']['docker/self-hosting/wayfindr-updater.conf']):
        raise Refusal('helper_provenance_unverified')
    config = upgrade.enrollment(host.api, ROOT)
    if config.value != host.config.value or config.token != host.config.token:
        raise Refusal('helper_provenance_unverified')
    install = Path(config.value['install_dir'])
    overlay = (ROOT / 'docker/self-hosting/compose.updater.yml').read_bytes().replace(
        b'__WAYFINDR_INSTALLATION_ID__', host.installation_id.encode('ascii'))
    if ((install / '.updater-enrolled').read_bytes() != (host.installation_id + '\n').encode()
            or (install / 'compose.updater.yml').read_bytes() != overlay):
        raise Refusal('helper_provenance_unverified')
    service = upgrade.service()
    if (service['ActiveState'] != 'active' or service['SubState'] != 'running'
            or not re.fullmatch(r'[1-9][0-9]*', service['MainPID'])
            or service['ControlGroup'] != '/system.slice/' + UNIT or service['FreezerState'] != 'running'
            or (transaction is not None and service['DropInPaths'] != str(upgrade.DROPIN))):
        raise Refusal('helper_identity_unverified')
    process = PROC / service['MainPID']
    if ((process / 'cmdline').read_bytes() != b'/usr/bin/python3\0/usr/local/lib/wayfindr-updater/updater.py\0serve\0'
            or (process / 'cgroup').read_text() != '0::/system.slice/' + UNIT + '\n'):
        raise Refusal('helper_identity_unverified')
    live = rpc_status(host.api, config, None, expected_pid=int(service['MainPID']))
    if (live['helper_version'] != selected['helper_version'] or live['generation'] != observed['status']['generation']
            or live['active_operation'] is not None):
        raise Refusal('helper_identity_unverified')
    # Root-owned state can change during an observation. Refuse a newly started
    # helper replacement rather than creating a marker from stale receipt bytes.
    verify_helper_generation(host, binding)
    return binding


def adopt(host, source, target, acknowledged, *, helper_release=None, helper_transaction=None):
    if acknowledged is not True:
        raise Refusal('explicit_disposable_acknowledgement_required')
    gate = module('update_vm_preflight').assess(source, target, helper_tag=helper_release or source)
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
    if observed['helper_version_source'] != 'authenticated_rpc':
        raise Refusal('helper_identity_unverified')
    helper = helper_provenance(host, gate, observed, helper_release, helper_transaction)
    host.api.trusted(STATE.parent, directory=True)
    STATE.mkdir(mode=0o700, exist_ok=True)
    host.api.trusted(STATE, directory=True)
    if STATE.stat().st_mode & 0o077:
        raise Refusal('disposable_marker_invalid')
    marker = {'schema': 2, 'purpose': 'disposable-update-qualification',
              'installation_id': host.installation_id, 'run_id': str(uuid.uuid4()),
              'vm_kind': 'dedicated_vm', 'created_at': utc(), 'initial_boot_id': boot_id(),
              'source': gate['source'], 'target': gate['target'], 'helper': helper}
    verify_helper_generation(host, helper)
    write_new(MARKER, marker)
    return {'schema': 1, 'scope': 'operator_isolation_attestation', 'qualification': False,
            'run_id': marker['run_id'], 'installation_id': host.installation_id}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    gate = commands.add_parser('preflight', help='Read-only published release gate; no VM or Docker access.')
    gate.add_argument('--source', required=True)
    gate.add_argument('--target', required=True)
    gate.add_argument('--helper-release', help='Also verify the exact published installed-helper distribution.')
    gate.add_argument('--output', type=Path, required=True)
    gate.add_argument('--evidence-output', type=Path, help='Also write an honest blocked/not-run evidence scaffold.')
    for action in ('adopt', 'snapshot', 'interrupt-helper', 'checkpoint-reboot', 'resume-reboot'):
        item = commands.add_parser(action)
        item.add_argument('--installation-id', required=True)
        if action == 'adopt':
            item.add_argument('--source', required=True)
            item.add_argument('--target', required=True)
            item.add_argument('--helper-release', help='Exact published distribution of the installed helper; defaults to source.')
            item.add_argument('--helper-upgrade-transaction', help='Completed supported helper-upgrade receipt UUID; requires --helper-release.')
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
            options = {} if args.helper_release is None else {'helper_tag': args.helper_release}
            result = module('update_vm_preflight').assess(args.source, args.target, **options)
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
            result = adopt(host, args.source, args.target, args.ack_disposable,
                           helper_release=args.helper_release, helper_transaction=args.helper_upgrade_transaction)
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
