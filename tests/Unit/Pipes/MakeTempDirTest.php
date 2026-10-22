<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\MakeTempDir;

function makeTempDirBuilder(string $tempDir): UpdateBuilderInterface
{
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getWorkTempDir')->andReturn($tempDir);

    return $builder;
}

beforeEach(function() {
    $this->root = sys_get_temp_dir() . '/streamline-maketemp-' . bin2hex(random_bytes(4));
    mkdir($this->base = $this->root . '/site', 0700, true);
    file_put_contents($this->base . '/index.php', 'live');
    app()->setBasePath($this->base);
    Event::fake();
});

afterEach(function() {
    exec('rm -rf ' . escapeshellarg($this->root));
});

it('creates the temporary directory with restrictive permissions when it does not exist', function() {
    $tempDir = $this->root . '/.work/nested';

    $result = (new MakeTempDir)($builder = makeTempDirBuilder($tempDir));

    expect($result)->toBe($builder)
        ->and(is_dir($tempDir))->toBeTrue()
        ->and(fileperms($tempDir) & 0777)->toBe(0700);
});

it('empties an existing work directory, including hidden files and nested trees', function() {
    $tempDir = $this->root . '/.work';
    mkdir("$tempDir/vendor/pkg", 0700, true);
    file_put_contents("$tempDir/vendor/pkg/a.php", 'x');
    file_put_contents("$tempDir/.env", 'SECRET=1');
    file_put_contents("$tempDir/removed-upstream.php", 'old');
    symlink($this->base, "$tempDir/link-to-site");

    (new MakeTempDir)(makeTempDirBuilder($tempDir));

    expect(is_dir($tempDir))->toBeTrue()
        ->and(array_diff(scandir($tempDir), ['.', '..']))->toBe([])
        ->and(file_get_contents($this->base . '/index.php'))->toBe('live');
});

it('refuses to empty a directory that is, or contains, the application', function(string $relative) {
    $tempDir = $this->root . $relative;

    expect(fn() => (new MakeTempDir)(makeTempDirBuilder($tempDir)))
        ->toThrow(RuntimeException::class, 'must be a separate directory');

    expect(file_get_contents($this->base . '/index.php'))->toBe('live');
})->with([
    'the base path'           => '/site',
    'the base path, trailing' => '/site/',
    'the base path via dots'  => '/site/../site/.',
    'its parent'              => '',
    'a grandparent'           => '/site/..',
]);

it('refuses the filesystem root', function() {
    expect(fn() => (new MakeTempDir)(makeTempDirBuilder('/')))
        ->toThrow(RuntimeException::class, 'must be a separate directory');
});

it('refuses a work directory that is a symlink to the application', function() {
    symlink($this->base, $link = $this->root . '/alias');

    expect(fn() => (new MakeTempDir)(makeTempDirBuilder($link)))
        ->toThrow(RuntimeException::class, 'must be a separate directory');

    expect(file_get_contents($this->base . '/index.php'))->toBe('live');
});

it('allows a sibling directory whose name merely starts with the base path name', function() {
    mkdir($tempDir = $this->root . '/site-work');
    file_put_contents("$tempDir/old", 'x');

    (new MakeTempDir)(makeTempDirBuilder($tempDir));

    expect(array_diff(scandir($tempDir), ['.', '..']))->toBe([]);
});

it('throws a RuntimeException when the directory can not be created', function() {
    file_put_contents($blocker = $this->root . '/blocker', 'a file');
    $tempDir = "$blocker/work";

    expect(fn() => @(new MakeTempDir)(makeTempDirBuilder($tempDir)))
        ->toThrow(RuntimeException::class);
});

it('dispatches an Event with the correct CommandClassCallback', function() {
    $tempDir = $this->root . '/.work';

    (new MakeTempDir)(makeTempDirBuilder($tempDir));

    Event::assertDispatched(CommandClassCallback::class, function(CommandClassCallback $event) use ($tempDir) {
        return $event->action === 'comment' && $event->value === "Creating temporary directory $tempDir";
    });
});
