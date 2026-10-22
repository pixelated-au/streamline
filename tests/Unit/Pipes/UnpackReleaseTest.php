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
