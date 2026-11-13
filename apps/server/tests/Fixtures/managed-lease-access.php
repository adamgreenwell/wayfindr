<?php

declare(strict_types=1);

namespace App\Support\Updates {
    function storage_path(string $suffix = ''): string
    {
        return $GLOBALS['lease_access_storage'].($suffix === '' ? '' : '/'.$suffix);
    }

    function leaseAccessGroupChange(string $path, int $group, bool $follow): bool
    {
        $victim = getenv('LEASE_ACCESS_REPLACE_GROUP_PATH');

        if (is_string($victim) && $victim !== '') {
            unlink($path);
            symlink($victim, $path);
        }

        return $follow ? \chgrp($path, $group) : \lchgrp($path, $group);
    }

    // Deterministic source-copy race: the real filesystem replaces the new name
    // immediately before the actual group syscall. Normal runs pass through.
    function lchgrp(string $path, int $group): bool
    {
        return leaseAccessGroupChange($path, $group, false);
    }

    function chgrp(string $path, int $group): bool
    {
        return leaseAccessGroupChange($path, $group, true);
    }

    function mkdir(string $path, int $mode): bool
    {
        $created = \mkdir($path, $mode);

        if ($created && getenv('LEASE_ACCESS_SUBSTITUTE_STAGING') === '1') {
            \chown($path, 17501);
        }

        return $created;
    }

    function chmod(string $path, int $mode): bool
    {
        $GLOBALS['lease_access_chmod_called'] = true;

        return \chmod($path, $mode);
    }

    function link(string $source, string $target): bool
    {
        if (getenv('LEASE_ACCESS_PAUSE_PUBLICATION') === '1') {
            echo json_encode(['publication' => 'waiting'])."\n";
            fflush(STDOUT);
            stream_set_timeout(STDIN, 15);
            \leaseAccessCheck(trim((string) fgets(STDIN)) === 'publish', 'publication_not_resumed');
        }

        return \link($source, $target);
    }
}

namespace {
    use App\Support\Updates\ManagedUpdateLease;

    function leaseAccessCheck(bool $condition, string $name): void
    {
        if (! $condition) {
            throw new RuntimeException($name);
        }
    }

    function leaseAccessRemove(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $path.'/'.$name;
            is_dir($child) && ! is_link($child) ? leaseAccessRemove($child) : unlink($child);
        }

        rmdir($path);
    }

    if (($argv[1] ?? '') === 'child') {
        [, , $leaseFile, $gateFile, $storage, $uid, $gid, $hold] = $argv;
        $GLOBALS['lease_access_storage'] = $storage;
        require $leaseFile;
        require $gateFile;

        try {
            leaseAccessCheck(posix_setgid((int) $gid) && posix_setuid((int) $uid), 'identity_change_failed');
            $lease = ManagedUpdateLease::acquire(null);
            $lease->assertNormal();
            echo json_encode(['ok' => true, 'uid' => posix_geteuid(), 'gid' => posix_getegid()])."\n";
            fflush(STDOUT);

            if ($hold === 'hold') {
                stream_set_timeout(STDIN, 15);
                leaseAccessCheck(trim((string) fgets(STDIN)) === 'release', 'release_not_received');
                $lease->assertNormal();
            }

            $lease->release();
        } catch (Throwable $failure) {
            $receipt = ['ok' => false, 'error' => $failure->getMessage()];
            if (getenv('LEASE_ACCESS_SUBSTITUTE_STAGING') === '1') {
                $receipt['chmod_called'] = $GLOBALS['lease_access_chmod_called'] ?? false;
            }
            echo json_encode($receipt)."\n";
        }

        exit(0);
    }

    function leaseAccessStart(string $leaseFile, string $gateFile, string $storage, int $uid, int $gid, bool $hold = false): array
    {
        $process = proc_open(
            [PHP_BINARY, __FILE__, 'child', $leaseFile, $gateFile, $storage, (string) $uid, (string) $gid, $hold ? 'hold' : 'release'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes,
        );
        leaseAccessCheck(is_resource($process), 'child_start_failed');
        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1], 2048);
        leaseAccessCheck(is_string($line), 'child_receipt_missing');
        $receipt = json_decode($line, true, 8, JSON_THROW_ON_ERROR);

        return [$process, $pipes, $receipt, $hold];
    }

    function leaseAccessFinish(array $child): void
    {
        [$process, $pipes] = $child;
        if ($child[3]) {
            @fwrite($pipes[0], "release\n");
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        leaseAccessCheck(proc_close($process) === 0, 'child_exit_failed');
    }

    function leaseAccessOnce(string $leaseFile, string $gateFile, string $storage, int $uid, int $gid): array
    {
        $child = leaseAccessStart($leaseFile, $gateFile, $storage, $uid, $gid);
        $receipt = $child[2];
        leaseAccessFinish($child);

        return $receipt;
    }

    $storage = sys_get_temp_dir().'/wayfindr-lease-access-'.bin2hex(random_bytes(16));
    $held = null;
    $publication = null;
    $checks = [];
    $exitCode = 0;

    try {
        leaseAccessCheck(PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() === 0, 'linux_root_required');
        [, $leaseFile, $gateFile] = $argv;
        leaseAccessCheck(mkdir($storage.'/framework', 0775, true), 'storage_create_failed');
        leaseAccessCheck(chgrp($storage.'/framework', 17500) && chmod($storage.'/framework', 0775) && chmod($storage, 0755), 'storage_permissions_failed');

        $creator = leaseAccessOnce($leaseFile, $gateFile, $storage, 0, 0);
        leaseAccessCheck(($creator['ok'] ?? false) === true, 'creator_can_acquire');
        $checks[] = 'root_creator';

        $held = leaseAccessStart($leaseFile, $gateFile, $storage, 17501, 17500, true);
        leaseAccessCheck(($held[2]['ok'] ?? false) === true && $held[2]['uid'] === 17501 && $held[2]['gid'] === 17500, 'first_group_reader_can_acquire');
        $path = $storage.'/framework/managed-upgrade.lock';
        $before = lstat($path);
        leaseAccessCheck($before['uid'] === 0 && $before['gid'] === 17500 && ($before['mode'] & 0777) === 0640 && $before['size'] === 0, 'new_lock_group_and_permissions');
        $checks[] = 'cross_uid_readonly_admission';

        $contender = leaseAccessOnce($leaseFile, $gateFile, $storage, 17502, 17500);
        leaseAccessCheck($contender === ['ok' => false, 'error' => 'managed_update_busy'], 'real_cross_uid_lifetime_exclusion');
        leaseAccessFinish($held);
        $held = null;
        $next = leaseAccessOnce($leaseFile, $gateFile, $storage, 17502, 17500);
        leaseAccessCheck(($next['ok'] ?? false) === true && $next['uid'] === 17502, 'second_group_reader_after_release');
        clearstatcache(true, $path);
        leaseAccessCheck(lstat($path) === $before, 'existing_inode_and_metadata_preserved');
        $checks[] = 'exclusive_lifetime_and_same_inode';

        $outside = leaseAccessOnce($leaseFile, $gateFile, $storage, 17503, 17503);
        leaseAccessCheck($outside === ['ok' => false, 'error' => 'managed_update_state_unavailable'], 'unreadable_lock_refused');
        $checks[] = 'outside_group_refused';

        chmod($path, 0440);
        $readonly = leaseAccessOnce($leaseFile, $gateFile, $storage, 17501, 17500);
        clearstatcache(true, $path);
        leaseAccessCheck(($readonly['ok'] ?? false) === true && (lstat($path)['mode'] & 0777) === 0440, 'existing_readonly_mode_preserved');
        $checks[] = 'preexisting_readonly_mode';

        file_put_contents($storage.'/framework/managed-upgrade.json', '{corrupt');
        $blocked = leaseAccessOnce($leaseFile, $gateFile, $storage, 17501, 17500);
        leaseAccessCheck($blocked === ['ok' => false, 'error' => 'managed_update_busy'], 'corrupt_marker_remains_closed');
        $checks[] = 'managed_marker_refusal';
        unlink($storage.'/framework/managed-upgrade.json');

        $concurrentStorage = $storage.'/concurrent';
        mkdir($concurrentStorage.'/framework', 0775, true);
        chgrp($concurrentStorage.'/framework', 17500);
        chmod($concurrentStorage.'/framework', 0775);
        putenv('LEASE_ACCESS_PAUSE_PUBLICATION=1');
        $publication = leaseAccessStart($leaseFile, $gateFile, $concurrentStorage, 0, 0);
        putenv('LEASE_ACCESS_PAUSE_PUBLICATION');
        leaseAccessCheck($publication[2] === ['publication' => 'waiting'], 'publication_pause_received');
        $held = leaseAccessStart($leaseFile, $gateFile, $concurrentStorage, 17501, 17500, true);
        leaseAccessCheck(($held[2]['ok'] ?? false) === true, 'concurrent_creator_acquired');
        $concurrentPath = $concurrentStorage.'/framework/managed-upgrade.lock';
        $winner = lstat($concurrentPath);
        fwrite($publication[1][0], "publish\n");
        $loser = json_decode((string) fgets($publication[1][1], 2048), true, 8, JSON_THROW_ON_ERROR);
        leaseAccessCheck($loser === ['ok' => false, 'error' => 'managed_update_busy'], 'publication_collision_keeps_winner_lock');
        leaseAccessFinish($publication);
        $publication = null;
        leaseAccessFinish($held);
        $held = null;
        clearstatcache(true, $concurrentPath);
        leaseAccessCheck(lstat($concurrentPath) === $winner && $winner['uid'] === 17501
            && (leaseAccessOnce($leaseFile, $gateFile, $concurrentStorage, 17502, 17500)['ok'] ?? false) === true, 'publication_collision_preserves_inode');
        $checks[] = 'concurrent_publication_keeps_same_inode';

        $substitutedStorage = $storage.'/substituted';
        mkdir($substitutedStorage.'/framework', 0775, true);
        chgrp($substitutedStorage.'/framework', 17500);
        putenv('LEASE_ACCESS_SUBSTITUTE_STAGING=1');
        $substituted = leaseAccessOnce($leaseFile, $gateFile, $substitutedStorage, 0, 0);
        putenv('LEASE_ACCESS_SUBSTITUTE_STAGING');
        leaseAccessCheck($substituted === ['ok' => false, 'error' => 'managed_update_state_unavailable', 'chmod_called' => false]
            && ! file_exists($substitutedStorage.'/framework/managed-upgrade.lock'), 'substituted_staging_refused_before_chmod');
        $checks[] = 'private_staging_owner_verified_before_chmod';

        $raceStorage = $storage.'/race';
        mkdir($raceStorage.'/framework', 0775, true);
        chgrp($raceStorage.'/framework', 17500);
        $victim = $storage.'/preserved';
        file_put_contents($victim, 'preserved');
        chmod($victim, 0600);
        $victimBefore = lstat($victim);
        putenv('LEASE_ACCESS_REPLACE_GROUP_PATH='.$victim);
        $race = leaseAccessOnce($leaseFile, $gateFile, $raceStorage, 0, 0);
        putenv('LEASE_ACCESS_REPLACE_GROUP_PATH');
        clearstatcache(true, $victim);
        leaseAccessCheck($race === ['ok' => false, 'error' => 'managed_update_state_unavailable']
            && lstat($victim) === $victimBefore && file_get_contents($victim) === 'preserved'
            && ! file_exists($raceStorage.'/framework/managed-upgrade.lock'), 'symlink_race_cannot_change_target');
        $checks[] = 'nofollow_initialization_race';

        echo json_encode(['schema' => 1, 'status' => 'passed', 'checks' => $checks, 'implementation_sha256' => hash_file('sha256', $leaseFile)])."\n";
    } catch (Throwable $failure) {
        echo json_encode(['schema' => 1, 'status' => 'failed', 'check' => $failure->getMessage(), 'completed' => $checks])."\n";
        $exitCode = 1;
    } finally {
        if ($publication !== null) {
            leaseAccessFinish($publication);
        }

        if ($held !== null) {
            leaseAccessFinish($held);
        }

        if (is_dir($storage)) {
            leaseAccessRemove($storage);
        }
    }

    exit($exitCode);
}
