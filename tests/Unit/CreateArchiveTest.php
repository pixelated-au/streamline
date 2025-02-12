<?php

use Illuminate\Support\Facades\Storage;
use Pixelated\Streamline\Actions\CreateArchive;

it('should create a .tar.gz file with correct structure and contents', function () {
    Storage::fake('local');

    $sourceFolder    = 'source';
    $destinationPath = 'destination';
    $filename        = 'test_archive.tar';

    // Define expected file structure and contents
    $expectedFiles = [
        'file1.txt' => '/set1',
        'file2.txt' => '/set1/level1',
        'file3.txt' => '/set1/level1/level2',
        'file4.txt' => '/set1/level1/level2/level3',
        'fileA.txt' => '/set2',
        'fileB.txt' => '/set2/level1',
        'fileC.txt' => '/set2/level1/level2',
        'fileD.txt' => '/set2/level1/level2/level3',
    ];

    Storage::makeDirectory($sourceFolder);
    Storage::makeDirectory($destinationPath);

    // Create directory structure and files
    foreach ($expectedFiles as $file => $dir) {
        $fullDir = "$sourceFolder$dir";
        Storage::makeDirectory($fullDir);
        Storage::put($fullDir . '/' . $file, "Content of $file");
    }

    Config::set('fake-production-environment', true);
    $createArchive = new CreateArchive(
        Storage::path($sourceFolder),
        Storage::path($destinationPath),
        $filename
    );
    $createArchive->create();

    $expectedTgzPath = "$destinationPath/$filename";
    Storage::assertExists($expectedTgzPath);

    // Verify the archive structure and contents
    $pharPath = 'phar://' . Storage::path($expectedTgzPath);

    foreach ($expectedFiles as $file => $dir) {
        $fullDir  = "$pharPath$dir";
        $fullPath = "$fullDir/$file";

        // Assert file exists
        expect(file_exists($fullPath))->toBeTrue("File $dir/$file does not exist in the archive.");

        // Assert file content
        $actualContent   = file_get_contents($fullPath);
        $expectedContent = "Content of $file";
        expect($actualContent)->toBe($expectedContent, "Content mismatch for file $dir/$file")
            ->and($fullDir)->toBeDirectory("$dir directory does not exist in the archive.");
    }
});

it('should throw an exception when the source folder does not exist', function () {
    $nonExistentFolder = 'non_existent_folder';
    File::shouldReceive('dirname')->andReturn('');
    File::shouldReceive('name')->andReturn('');
    File::shouldReceive('exists')->andReturnFalse();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Source folder '$nonExistentFolder' does not exist.");
    (new CreateArchive($nonExistentFolder, '', ''))
        ->create();
});

it('should throw an exception when the destination directory cannot be created', function () {
    $destinationPath = '/non-existent/directory';

    File::shouldReceive('dirname')->andReturn('');
    File::shouldReceive('exists')->andReturnTrue();
    File::shouldReceive('name')->andReturn('');
    File::shouldReceive('isDirectory')->andReturnFalse();
    File::shouldReceive('makeDirectory')->andReturnFalse();

    $createArchive = new CreateArchive('', $destinationPath, '');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Directory "/non-existent/directory" was not created');

    (fn() => $this->checkDestinationPath())->call($createArchive);
});

it('should throw an exception when the destination path is not writable', function () {
    $destinationPath = '/path/to/destination';

    File::shouldReceive('dirname')->andReturn('');
    File::shouldReceive('exists')->andReturnTrue();
    File::shouldReceive('name')->andReturn('');
    File::shouldReceive('isDirectory')->andReturnTrue();
    File::shouldReceive('isWritable')->with($destinationPath)->andReturnFalse();

    $createArchive = new CreateArchive('', $destinationPath, '');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Destination path '$destinationPath' is not writable.");

    (fn() => $this->checkDestinationPath())->call($createArchive);
});

it('should throw an exception when the archive file already exists', function () {
    $destinationPath = '/path/to/destination';
    $filename        = 'test_archive.tgz';

    File::shouldReceive('dirname')->andReturn('');
    File::shouldReceive('exists')->andReturn([true, false]);
    File::shouldReceive('name')->andReturn($filename);
    File::shouldReceive('isDirectory')->andReturnTrue();
    File::shouldReceive('isWritable')->andReturnTrue();

    $createArchive = new CreateArchive('', $destinationPath, $filename);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Archive file '$destinationPath/$filename' already exists.");

    (fn() => $this->checkDestinationPath())->call($createArchive);
});
