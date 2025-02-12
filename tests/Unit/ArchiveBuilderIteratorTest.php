<?php

use Pixelated\Streamline\Iterators\ArchiveBuilderIterator;

it('returns next non symlink file when current is symlink', function () {
    $disk = Storage::fake('local');

    $disk->put('file1.txt', 'contents');
    $disk->put('file2.txt', 'contents');
    symlink($disk->path('file1.txt'), $disk->path('symlink.txt'));

    $iterator = new ArchiveBuilderIterator($disk->path(''));

    $matchedFiles = [
        'file1.txt'   => false,
        'file2.txt'   => false,
        'symlink.txt' => false,
    ];

    foreach ($iterator as $item) {
        $this->assertArrayHasKey($item->getFilename(), $matchedFiles);
        $matchedFiles[$item->getFilename()] = true;
        $this->assertFalse($item->isLink());
    }
    $this->assertTrue($matchedFiles['file1.txt']);
    $this->assertTrue($matchedFiles['file2.txt']);
    $this->assertFalse($matchedFiles['symlink.txt']);
});

it('should handle a directory containing only symlinks', function () {
    $disk = Storage::fake('local');

    // Create a directory with only symlinks
    $disk->put('target1.txt', 'contents1');
    $disk->put('target2.txt', 'contents2');
    symlink($disk->path('target1.txt'), $disk->path('symlink1.txt'));
    symlink($disk->path('target2.txt'), $disk->path('symlink2.txt'));

    $iterator = new ArchiveBuilderIterator($disk->path(''));

    $fileCount = 0;
    foreach ($iterator as $item) {
        $fileCount++;
        $this->assertFalse($item->isLink(), "Item {$item->getFilename()} should not be a symlink");
        $this->assertContains($item->getFilename(), ['target1.txt', 'target2.txt'], "Unexpected file: {$item->getFilename()}");
    }

    $this->assertEquals(2, $fileCount, "Expected 2 files, got $fileCount");
});

it('should return the same SplFileInfo object when the current file is not a symlink', function () {
    $disk = Storage::fake('local');
    $disk->put('file.txt', 'contents');

    $iterator = new ArchiveBuilderIterator($disk->path(''));
    $originalCurrent = $iterator->current();
    $returnedCurrent = $iterator->current();

    expect($returnedCurrent)->toBeInstanceOf(SplFileInfo::class)
        ->and($returnedCurrent->getFilename())->toBe('file.txt')
        ->and($returnedCurrent)->toBe($originalCurrent);
});
