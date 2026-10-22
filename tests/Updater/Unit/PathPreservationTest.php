<?php

use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

/*
 * These tests use a real filesystem (a unique directory under the system temp dir) because they
 * exercise symbolic links, file modes and wildcard expansion, none of which vfsStream models.
 */

beforeEach(function() {
    $this->root = sys_get_temp_dir() . '/streamline-pp-' . bin2hex(random_bytes(6));
    $this->base = "$this->root/live";
    $this->new  = "$this->root/incoming";

    mkdir($this->base, 0755, true);
    mkdir($this->new, 0755, true);
});

afterEach(function() {
    exec('rm -rf ' . escapeshellarg($this->root));
});

function pp(string $base, string $new, array $protected = [], array $carry = []): RunCompleteGitHubVersionRelease
{
    return new RunCompleteGitHubVersionRelease(
        tempDirName: $new,
        laravelBasePath: $base,
        publicDirName: 'public',
        frontendBuildDir: 'build',
        installingVersion: '1.0.0',
        composerPath: '/dev/null',
        protectedPaths: $protected,
        dirPermission: 0755,
        filePermission: 0644,
        protectedFilePermission: 0600,
        oldReleaseArchivePath: '',
        doRetainOldReleaseDir: false,
        doOutput: false,
        carryForwardPaths: $carry,
    );
}

function ppWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $contents);
}

function ppPerms(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 0777;
}

it('lets protected win when a path is listed as both protected and carried forward', function() {
    ppWrite("$this->base/settings.json", 'old');
    ppWrite("$this->new/settings.json", 'incoming');

    $updater = pp($this->base, $this->new, ['settings.json'], ['settings.json']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(file_get_contents("$this->new/settings.json"))->toBe('old')
        ->and(ppPerms("$this->new/settings.json"))->toBe(0600);
});

it('preserves a nested protected file inside a carried directory', function() {
    ppWrite("$this->base/data/secret.txt", 'secret');
    ppWrite("$this->base/data/public.txt", 'public');
    ppWrite("$this->new/data/secret.txt", 'incoming secret');

    $updater = pp($this->base, $this->new, ['data/secret.txt'], ['data']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(file_get_contents("$this->new/data/secret.txt"))->toBe('secret')
        ->and(ppPerms("$this->new/data/secret.txt"))->toBe(0600)
        ->and(file_get_contents("$this->new/data/public.txt"))->toBe('public')
        ->and(ppPerms("$this->new/data/public.txt"))->toBe(0644);
});

it('carries a single file forward with the ordinary file permission and creates missing parents', function() {
    ppWrite("$this->base/storage/app/one.txt", 'one');
    chmod("$this->base/storage/app/one.txt", 0600);

    $updater = pp($this->base, $this->new, [], ['storage/app/one.txt']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);

    expect(file_get_contents("$this->new/storage/app/one.txt"))->toBe('one')
        ->and(ppPerms("$this->new/storage/app/one.txt"))->toBe(0644);
});

it('expands wildcard protected paths against the old release (i14)', function() {
    ppWrite("$this->base/storage/a.txt", 'a');
    ppWrite("$this->base/storage/sub/b.txt", 'b');
    ppWrite("$this->base/app/c.php", 'c');

    $updater = pp($this->base, $this->new, ['storage/*']);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(file_get_contents("$this->new/storage/a.txt"))->toBe('a')
        ->and(file_get_contents("$this->new/storage/sub/b.txt"))->toBe('b')
        ->and(ppPerms("$this->new/storage/a.txt"))->toBe(0600)
        ->and(file_exists("$this->new/app/c.php"))->toBeFalse();
});

it('expands wildcards in a middle segment and in carried forward paths', function() {
    ppWrite("$this->base/tenants/one/uploads/x.jpg", 'x');
    ppWrite("$this->base/tenants/two/uploads/y.jpg", 'y');
    ppWrite("$this->base/tenants/two/other/z.jpg", 'z');

    $updater = pp($this->base, $this->new, [], ['tenants/*/uploads']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);

    expect(file_exists("$this->new/tenants/one/uploads/x.jpg"))->toBeTrue()
        ->and(file_exists("$this->new/tenants/two/uploads/y.jpg"))->toBeTrue()
        ->and(file_exists("$this->new/tenants/two/other/z.jpg"))->toBeFalse();
});

it('does not match dotfiles with a bare wildcard and ignores wildcards that match nothing', function() {
    ppWrite("$this->base/dir/.hidden", 'h');
    ppWrite("$this->base/dir/shown", 's');

    $updater = pp($this->base, $this->new, ['dir/*', 'nothing/*']);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(file_exists("$this->new/dir/shown"))->toBeTrue()
        ->and(file_exists("$this->new/dir/.hidden"))->toBeFalse();
});

it('never lets a wildcard or traversal escape the base path', function() {
    ppWrite("$this->root/outside.txt", 'outside');
    ppWrite("$this->base/inside.txt", 'inside');

    $updater = pp($this->base, $this->new, ['../*', '../outside.txt', 'a/../../outside.txt'], ['../outside.txt']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(scandir($this->new))->toBe(['.', '..'])
        ->and(file_exists("$this->root/incoming/outside.txt"))->toBeFalse();
});

it('ignores empty, dot and dot-slash entries instead of copying the whole installation (i3)', function() {
    ppWrite("$this->base/.env", 'APP_KEY=1');
    ppWrite("$this->base/app/file.php", 'x');

    $updater = pp($this->base, $this->new, ['', '.', './', ' ', '/'], ['', '.', './']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(scandir($this->new))->toBe(['.', '..']);
});

it('normalises a leading ./ so the path is still preserved', function() {
    ppWrite("$this->base/.env", 'APP_KEY=1');

    $updater = pp($this->base, $this->new, ['./.env']);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(file_get_contents("$this->new/.env"))->toBe('APP_KEY=1');
});

it('recreates symlinks in carried trees as symlinks with the target preserved verbatim (i10)', function() {
    ppWrite("$this->base/public/real/file.txt", 'real');
    mkdir("$this->base/public/uploads", 0755, true);
    ppWrite("$this->base/public/uploads/a.txt", 'a');
    symlink('a.txt', "$this->base/public/uploads/rel-file");   // relative, to a file
    symlink('../real', "$this->base/public/uploads/rel-dir");  // relative, to a directory
    symlink("$this->base/public/real", "$this->base/public/uploads/abs-dir"); // absolute

    $updater = pp($this->base, $this->new, [], ['public/uploads']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);

    foreach (['rel-file' => 'a.txt', 'rel-dir' => '../real', 'abs-dir' => "$this->base/public/real"] as $name => $target) {
        expect(is_link("$this->new/public/uploads/$name"))->toBeTrue("$name should be a symlink")
            ->and(readlink("$this->new/public/uploads/$name"))->toBe($target);
    }

    // The linked directory was not copied as a real directory.
    expect(file_exists("$this->new/public/uploads/rel-dir/file.txt"))->toBeFalse();
});

it('does not recurse forever on a symlink loop', function() {
    mkdir("$this->base/loop", 0755, true);
    ppWrite("$this->base/loop/f.txt", 'f');
    symlink('..', "$this->base/loop/up");
    symlink('.', "$this->base/loop/self");

    $updater = pp($this->base, $this->new, ['loop'], ['loop']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(is_link("$this->new/loop/up"))->toBeTrue()
        ->and(readlink("$this->new/loop/up"))->toBe('..')
        ->and(is_link("$this->new/loop/self"))->toBeTrue()
        ->and(file_get_contents("$this->new/loop/f.txt"))->toBe('f');
});

it('preserves relative and absolute symlinks inside a protected directory, and overwrites incoming ones', function() {
    ppWrite("$this->base/keep/target.txt", 't');
    symlink('target.txt', "$this->base/keep/rel");
    symlink("$this->base/keep/target.txt", "$this->base/keep/abs");
    symlink('/etc', "$this->base/keep/etc");
    ppWrite("$this->new/keep/rel", 'incoming regular file');

    $updater = pp($this->base, $this->new, ['keep']);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(is_link("$this->new/keep/rel"))->toBeTrue()
        ->and(readlink("$this->new/keep/rel"))->toBe('target.txt')
        ->and(readlink("$this->new/keep/abs"))->toBe("$this->base/keep/target.txt")
        ->and(is_link("$this->new/keep/etc"))->toBeTrue()
        ->and(file_exists("$this->new/keep/etc/passwd"))->toBeTrue(); // via the link, never copied
    expect(file_exists("$this->new/keep/passwd"))->toBeFalse();
});

it('preserves a protected path that is itself a symlink, including a dangling one', function() {
    ppWrite("$this->base/storage/app/public/p.txt", 'p');
    mkdir("$this->base/public", 0755, true);
    symlink('../storage/app/public', "$this->base/public/storage");
    symlink('missing-target', "$this->base/dangling");

    $updater = pp($this->base, $this->new, ['public/storage', 'dangling']);
    (fn() => $this->preserveProtectedPaths())->call($updater);

    expect(readlink("$this->new/public/storage"))->toBe('../storage/app/public')
        ->and(readlink("$this->new/dangling"))->toBe('missing-target');
});

it('does not replace an incoming path with a carried symlink', function() {
    mkdir("$this->base/uploads", 0755, true);
    symlink('x', "$this->base/uploads/l");
    ppWrite("$this->new/uploads/l", 'incoming');

    $updater = pp($this->base, $this->new, [], ['uploads']);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);

    expect(is_link("$this->new/uploads/l"))->toBeFalse()
        ->and(file_get_contents("$this->new/uploads/l"))->toBe('incoming');
});

it('keeps the helper wildcard matcher working on glob patterns', function() {
    $updater = pp($this->base, $this->new, ['config/*', 'a/?/c', 'plain']);
    $match   = fn(string $path) => (fn() => $this->isProtectedWildcardPath($path))->call($updater);

    expect($match('config/app.php'))->toBeTrue()
        ->and($match('a/b/c'))->toBeTrue()
        ->and($match('plain'))->toBeFalse()
        ->and($match('other/x'))->toBeFalse();
});

it('preserves the source mtime when carrying old build assets forward', function() {
    ppWrite("$this->base/public/build/assets/app-AAAA1111.js", 'old');
    ppWrite("$this->base/storage/app/one.txt", 'one');
    $old = time() - 86400 * 30;
    touch("$this->base/public/build/assets/app-AAAA1111.js", $old);
    touch("$this->base/storage/app/one.txt", $old);
    mkdir("$this->new/public/build", 0755, true);

    $updater = pp($this->base, $this->new, [], ['storage/app/one.txt']);
    $from    = "$this->base/public/build";
    $to      = "$this->new/public/build";
    (fn() => $this->recursiveCopyOldBuildFilesToNewDir($from, $to))->call($updater);
    (fn() => $this->carryForwardRetainedPaths())->call($updater);

    clearstatcache();

    expect(filemtime("$this->new/public/build/assets/app-AAAA1111.js"))->toBe($old)
        ->and(filemtime("$this->new/storage/app/one.txt"))->toBe($old);
});
