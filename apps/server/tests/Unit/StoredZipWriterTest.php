<?php

use App\Support\Zip\ArchiveTooLarge;
use App\Support\Zip\StoredZipWriter;

function storedZipPath(): string
{
    return sys_get_temp_dir().'/wayfindr-zip-test-'.bin2hex(random_bytes(6)).'.zip';
}

test('an archive opens in the zip extension and in unzip, entry for entry', function (): void {
    $path = storedZipPath();
    $big = random_bytes(300_000);
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $big);
    rewind($stream);

    $zip = new StoredZipWriter($path);
    $zip->addFromString('README.txt', "hello\n");
    $zip->begin('conversations/WF-ABC123.json');
    $zip->write('{"a":');
    $zip->write('[1,2]}');
    $zip->end();
    $zip->addFromStream('attachments/7-Grüße.png', $stream);
    $zip->addFromString('empty.txt', '');
    $zip->close();

    $read = new ZipArchive;
    expect($read->open($path, ZipArchive::CHECKCONS))->toBeTrue('the archive is not a consistent ZIP file')
        ->and($read->numFiles)->toBe(4)
        ->and($read->getFromName('README.txt'))->toBe("hello\n")
        ->and($read->getFromName('conversations/WF-ABC123.json'))->toBe('{"a":[1,2]}')
        ->and($read->getFromName('attachments/7-Grüße.png'))->toBe($big, 'a streamed entry came back different')
        ->and($read->getFromName('empty.txt'))->toBe('');
    $read->close();

    if (is_executable('/usr/bin/unzip')) {
        exec('/usr/bin/unzip -tq '.escapeshellarg($path).' 2>&1', $output, $status);
        expect($status)->toBe(0, 'unzip rejects the archive: '.implode(' ', $output));
    }

    unlink($path);
});

test('an entry name that could leave the archive, or hide, is refused', function (string $name): void {
    $path = storedZipPath();
    $zip = new StoredZipWriter($path);

    try {
        expect(fn () => $zip->addFromString($name, 'x'))->toThrow(InvalidArgumentException::class);
    } finally {
        $zip->abandon();
        unlink($path);
    }
})->with([
    'absolute' => ['/etc/passwd'],
    'parent' => ['attachments/../../evil'],
    'backslash' => ['attachments\\evil'],
    'control character' => ["attachments/a\nb"],
    'empty' => [''],
]);

test('entries are written one at a time', function (): void {
    $path = storedZipPath();
    $zip = new StoredZipWriter($path);
    $zip->begin('a.txt');

    try {
        expect(fn () => $zip->begin('b.txt'))->toThrow(RuntimeException::class)
            ->and(fn () => $zip->close())->toThrow(RuntimeException::class);
    } finally {
        $zip->abandon();
        unlink($path);
    }
});

test('an archive is held to the size its caller allows as it is written', function (): void {
    $path = storedZipPath();
    $zip = new StoredZipWriter($path, 1024);

    try {
        $zip->addFromString('small.txt', str_repeat('a', 512));

        // Past the allowance part way through an entry, not only at the end.
        expect(fn () => $zip->addFromString('large.txt', str_repeat('b', 1024)))->toThrow(ArchiveTooLarge::class);
    } finally {
        $zip->abandon();
        unlink($path);
    }

    expect(fn () => new StoredZipWriter($path, StoredZipWriter::MAX_BYTES + 1))->toThrow(InvalidArgumentException::class);
});
