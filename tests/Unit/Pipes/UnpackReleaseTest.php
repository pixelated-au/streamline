<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\UnpackRelease;

const OPSYS_UNIX = 3;

function unpackBuilder(string $tempDir = '/path/to/temp'): UpdateBuilderInterface
{
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn('/path/to/archive.zip');
    $builder->shouldReceive('getWorkTempDir')->andReturn($tempDir);

    return $builder;
}

/**
 * @param  array<int, array{name: string, opsys?: int, attributes?: int}>  $entries
 */
function fakeZip(array $entries, bool $canOpen = true): ZipArchive
{
    $zip = Mockery::mock(ZipArchive::class);
    $zip->shouldReceive('open')->andReturn($canOpen);
    $zip->shouldReceive('count')->andReturn(count($entries));

    foreach ($entries as $index => $entry) {
        $zip->shouldReceive('getNameIndex')->with($index)->andReturn($entry['name']);
        $zip->shouldReceive('getExternalAttributesIndex')
            ->with($index, Mockery::any(), Mockery::any())
            ->andReturnUsing(function($i, &$opsys, &$attributes) use ($entry) {
                $opsys      = $entry['opsys']      ?? OPSYS_UNIX;
                $attributes = $entry['attributes'] ?? (0100644 << 16);

                return true;
            });
    }

    return $zip;
}

it('should dispatch an Event with CommandClassCallback when invoked', function() {
    $zip = fakeZip([['name' => 'app/Console/Kernel.php']]);
    $zip->shouldReceive('extractTo')->once();
    $zip->shouldReceive('close')->once();

    Event::fake();

    $result = (new UnpackRelease($zip))($builder = unpackBuilder());

    expect($result)->toBe($builder);
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'info' && $event->value === 'Unpacking archive'
    );
});

it('should throw a RuntimeException when zip->open fails', function() {
    $zip = Mockery::mock(ZipArchive::class);
    $zip->shouldReceive('open')->andReturn(false);

    Event::fake();

    expect(fn() => (new UnpackRelease($zip))(unpackBuilder()))
        ->toThrow(RuntimeException::class, 'Error: Failed to unpack /path/to/archive.zip');

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'info' && $event->value === 'Unpacking archive'
    );
});

// extractTo() does strip literal "../" segments, but it will follow a symlink entry and write
// through it, which is how a crafted archive escapes the working directory. The zip attributes are
// read through by-reference out-parameters that a mock cannot populate, so the bit handling that
// identifies a symlink is asserted directly.
it('identifies a unix symlink entry from its external attributes', function(int $opsys, int $mode, bool $expected) {
    $unpackRelease = new UnpackRelease(Mockery::mock(ZipArchive::class));

    expect($unpackRelease->isUnixSymlinkMode($opsys, $mode << 16))->toBe($expected);
})->with([
    'symlink, 0777'     => [OPSYS_UNIX, 0120777, true],
    'symlink, 0644'     => [OPSYS_UNIX, 0120644, true],
    'regular file'      => [OPSYS_UNIX, 0100644, false],
    'directory'         => [OPSYS_UNIX, 0040755, false],
    'symlink bits, dos' => [0, 0120777, false],
]);

it('refuses an archive with an entry that resolves outside the working directory', function() {
    $zip = fakeZip([['name' => '../../etc/cron.d/payload']]);
    $zip->shouldNotReceive('extractTo');
    $zip->shouldReceive('close')->once();

    Event::fake();

    expect(fn() => (new UnpackRelease($zip))(unpackBuilder()))
        ->toThrow(RuntimeException::class, 'would be written outside the working directory');
});

it('allows a path that contains a traversal segment but stays inside the working directory', function() {
    $zip = fakeZip([['name' => 'public/../app/Kernel.php']]);
    $zip->shouldReceive('extractTo')->once();
    $zip->shouldReceive('close')->once();

    Event::fake();

    $result = (new UnpackRelease($zip))($builder = unpackBuilder());

    expect($result)->toBe($builder);
});

/**
 * Builds a real zip so the behaviour of ZipArchive itself is exercised rather than a mock's.
 *
 * @param  array<string, array{content?: string, mode?: int}>  $entries
 */
function buildRealZip(string $path, array $entries): void
{
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($entries as $name => $entry) {
        $zip->addFromString($name, $entry['content'] ?? 'x');

        if (isset($entry['mode'])) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $entry['mode'] << 16);
        }
    }

    $zip->close();
}

function makeRealDir(string $name): string
{
    $dir = sys_get_temp_dir() . '/streamline-unpack-' . $name . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

/** @return list<string> */
function listTree(string $dir): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $found[] = substr($file->getPathname(), strlen($dir) + 1);
    }

    return $found;
}

function realUnpackBuilder(string $archive, string $workDir): UpdateBuilderInterface
{
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn($archive);
    $builder->shouldReceive('getWorkTempDir')->andReturn($workDir);

    return $builder;
}

afterEach(function() {
    foreach ($this->realDirs ?? [] as $dir) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

it('refuses a real archive containing a symlink entry and extracts nothing', function() {
    $this->realDirs = [$root = makeRealDir('symlink')];
    mkdir($work = "$root/work");
    buildRealZip($archive = "$root/release.zip", [
        'ok.txt'         => [],
        'link'           => ['content' => '/etc', 'mode' => 0120777],
        'link/pwned.txt' => [],
    ]);
    Event::fake();

    expect(fn() => (new UnpackRelease(new ZipArchive))(realUnpackBuilder($archive, $work)))
        ->toThrow(RuntimeException::class, 'symbolic link');

    expect(listTree($work))->toBe([]);
});

it('refuses a real archive with an unsafe entry name and extracts nothing', function(string $name) {
    $this->realDirs = [$root = makeRealDir('traversal')];
    mkdir($work = "$root/work");
    buildRealZip($archive = "$root/release.zip", ['ok.txt' => [], $name => []]);
    Event::fake();

    expect(fn() => (new UnpackRelease(new ZipArchive))(realUnpackBuilder($archive, $work)))
        ->toThrow(RuntimeException::class);

    expect(listTree($work))->toBe([])
        ->and(file_exists("$root/x"))->toBeFalse();
})->with([
    'parent'        => '../x',
    'nested parent' => 'a/../../x',
    'bare dotdot'   => '..',
    'backslashes'   => '..\\..\\x',
    'absolute'      => '/abs/path',
    'windows drive' => 'C:/x',
    'backslash abs' => '\\abs\\path',
]);

it('does not apply setuid, setgid or sticky bits from the archive', function() {
    $this->realDirs = [$root = makeRealDir('setuid')];
    mkdir($work = "$root/work");
    buildRealZip($archive = "$root/release.zip", [
        'bin/tool'  => ['mode' => 0104755],
        'bin/two'   => ['mode' => 0102755],
        'bin/three' => ['mode' => 0101755],
    ]);
    Event::fake();

    (new UnpackRelease(new ZipArchive))(realUnpackBuilder($archive, $work));

    foreach (['tool', 'two', 'three'] as $file) {
        expect(fileperms("$work/bin/$file") & 07000)->toBe(0);
    }
});

it('refuses an archive whose entry name cannot be read', function() {
    $zip = Mockery::mock(ZipArchive::class);
    $zip->shouldReceive('open')->andReturnTrue();
    $zip->shouldReceive('count')->andReturn(1);
    $zip->shouldReceive('getNameIndex')->with(0)->andReturnFalse();
    $zip->shouldNotReceive('extractTo');
    $zip->shouldReceive('close')->once();
    Event::fake();

    expect(fn() => (new UnpackRelease($zip))(unpackBuilder()))
        ->toThrow(RuntimeException::class);
});

it('leaves nothing behind when the archive cannot be opened', function() {
    $this->realDirs = [$root = makeRealDir('badopen')];
    mkdir($work = "$root/work");
    file_put_contents($archive = "$root/release.zip", 'this is not a zip');
    Event::fake();

    expect(fn() => (new UnpackRelease(new ZipArchive))(realUnpackBuilder($archive, $work)))
        ->toThrow(RuntimeException::class, 'Failed to unpack');

    expect(listTree($work))->toBe([]);
});

it('removes the downloaded archive after extracting so it is not deployed with the release', function() {
    $this->realDirs = [$root = makeRealDir('archive')];
    mkdir($work = "$root/work");
    buildRealZip($archive = "$work/release.zip", ['app.php' => []]);
    Event::fake();

    (new UnpackRelease(new ZipArchive))(realUnpackBuilder($archive, $work));

    expect(file_exists("$work/app.php"))->toBeTrue()
        ->and(file_exists($archive))->toBeFalse();
});
