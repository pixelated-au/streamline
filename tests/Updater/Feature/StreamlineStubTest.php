<?php

use Pixelated\Streamline\Tests\Updater\Feature\Traits\Filters;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;

pest()->use(Filters::class);

describe('skipping', function () {

it('fails to run when there are no arguments', function () {
    $stub = $this->getStreamlineStub();

    $process = Process::fromShellCommandline("php $stub");
    $process->run();

    $regex = '(?(DEFINE)(?<cmd>\x1b\[(?:\d{1,2})m))' // define pattern called 'cmd' for ANSI escape codes. Should match something like: \033[1m
        . '^'                             // start of the line
        . '(?P>cmd){2}'                   // match the 'cmd' pattern twice
        . 'Usage:'                        // match 'usage:'
        . '(?P>cmd){2}'                   // match the 'cmd' pattern again (twice)
        . ' php [\w\/]*streamline\.stub'  // match the script path
        . ' {security-key}'               // match the security key
        . '(?P>cmd)'                      // match the 'cmd' pattern (once more)
        . '$';                            // end of the line

    expect($process->getOutput())->toMatch("/$regex/");
});

it('verifies the security key', function () {
//    $configFile = $this->readConfigFile();
//    $stubFile = $this->readStubFile($configFile);

//    $process = new Process(
//        command: ['php', ''],
//        input: $stubFile,
//    );
//    $this->tidyUp();

    $stubFile = file_get_contents(getStreamlineStub());

    $process = new PhpProcess($stubFile);

    $process->run(function (string $type, string $output) {
        dump("Output: $output\n");
        if ($type === Process::OUT) {
            dump($output);
        } else {
            dump("Error: $output\n");
        }
    });
});

function getStreamlineStub(): string
{
    return package_path('stubs/streamline.php.stub');
}

function getConfigStub(): string
{
    return package_path('stubs/StreamlineConfig.stub');
}

function package_path(array|string $path = ''): string
{
    $path = str_starts_with($path, './')
        ? rtrim($path, '/') . '/' . mb_substr($path, 2)
        : $path;

    return getcwd() . '/' . $path;
}
})->skip('deprecated');
