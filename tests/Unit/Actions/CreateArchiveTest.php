<?php

use Illuminate\Support\Facades\Storage;
use Pixelated\Streamline\Actions\CreateArchive;

it('should create a .tar.gz file with correct structure and contents',
    /**
     * @throws \League\Flysystem\FilesystemException
     */
    function () {
        Storage::fake('local');

        $sourceFolder      = 'source';
        $destinationFolder = 'destination';
        $filename          = 'test_archive.zip';

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
        Storage::makeDirectory($destinationFolder);

        // Create directory structure and files
        foreach ($expectedFiles as $file => $dir) {
            $fullDir = "$sourceFolder$dir";
            Storage::makeDirectory($fullDir);
            Storage::put($fullDir . '/' . $file, "Content of $file");
        }

        Config::set('fake-production-environment', true);
        $createArchive = new CreateArchive(
            Storage::path($sourceFolder),
            Storage::path($destinationFolder),
            $filename
        );
        $createArchive->create();

        $expectedZipPath = "$destinationFolder/$filename";
        Storage::assertExists($expectedZipPath);

        $zipFile = new ZipArchive;
        $zipFile->open(Storage::path($expectedZipPath));

        // Iterate through the ZIP archive
        for ($i = 0; $i < $zipFile->numFiles; $i++) {
            $stat     = $zipFile->statIndex($i);
            $filename = $stat['name'];

            // Check if the file exists in our expected files
            $found = false;

            foreach ($expectedFiles as $expectedFile => $expectedDir) {
                if ($filename === ltrim($expectedDir . '/' . $expectedFile, '/')) {
                    $found = true;

                    // Assert file content
                    $actualContent   = $zipFile->getFromIndex($i);
                    $expectedContent = "Content of $expectedFile";
                    expect($actualContent)->toBe($expectedContent, "Content mismatch for file $filename");

                    // Assert directory structure
                    $dirPath = dirname($filename);
                    expect($dirPath)->toBe(ltrim($expectedDir, '/'), "Directory structure mismatch for $filename");

                    break;
                }
            }

            expect($found)->toBeTrue("Unexpected file $filename found in the archive");
        }

        // Assert that all expected files were found
        expect(count($expectedFiles))->toBe($zipFile->numFiles, "Number of files in archive doesn't match expected");

        $zipFile->close();

        Storage::deleteDirectory($sourceFolder);
        Storage::deleteDirectory($destinationFolder);
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

    (fn () => $this->checkDestinationPath())->call($createArchive);
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

    (fn () => $this->checkDestinationPath())->call($createArchive);
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

    (fn () => $this->checkDestinationPath())->call($createArchive);
});
