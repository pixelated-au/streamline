<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Factories\ProcessFactory;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\RunUpdate;
use Symfony\Component\Process\Process;

/**
 * Runs a real child PHP process the same way the updater is launched, using a stand-in runner
 * class, to pin down the contract between the command and the updater process.
 */
function runWithStandInUpdater(string $body, ?array &$events = null): ?Throwable
{
    static $counter = 0;

    $class = 'StandInUpdater' . ++$counter . '_' . getmypid();
    $file  = sys_get_temp_dir() . "/$class.php";
    file_put_contents($file, "<?php class $class { public function run(): void { $body } }");

    require_once $file;

    Config::set('streamline.runner_class', $class);
    Config::set('streamline.external_process_class', Process::class);

    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturn('1.0.0');
    $builder->shouldReceive('getComposerPath')->andReturn('composer');
    $builder->shouldReceive('getBackupArchivePath')->andReturn(null);
    $builder->shouldReceive('markUpdateCompleted')->andReturnSelf();

    Event::fake([CommandClassCallback::class]);

    $runUpdate = new RunUpdate(new InstantiateStreamlineUpdater(new ProcessFactory(Process::class), $class));

    $caught = null;

    try {
        $runUpdate($builder);
    } catch (Throwable $e) {
        $caught = $e;
    }

    $events = Event::dispatched(CommandClassCallback::class)->map(fn(array $args) => [$args[0]->action, $args[0]->value])->all();
    unlink($file);

    return $caught;
}

it('fails when the updater dies with a non-zero exit code and only stdout output', function() {
    $caught = runWithStandInUpdater('echo "Fatal error: boom"; exit(255);');

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('exit code 255')
        ->and($caught->getMessage())->toContain('Fatal error: boom');
});

it('succeeds when the updater exits cleanly', function() {
    $caught = runWithStandInUpdater('echo "all good";', $events);

    expect($caught)->toBeNull()
        ->and(collect($events)->contains(fn($e) => $e[0] === 'line' && str_contains($e[1], 'all good')))->toBeTrue();
});

it('does not kill the updater when it writes a warning to stderr', function() {
    $marker = sys_get_temp_dir() . '/updater-marker-' . getmypid();
    @unlink($marker);

    $caught = runWithStandInUpdater(
        'fwrite(STDERR, "PHP Warning: chmod failed"); usleep(300000); touch("' . $marker . '"); echo "finished";',
        $events
    );

    expect($caught)->toBeNull()
        ->and(file_exists($marker))->toBeTrue()
        ->and(collect($events)->contains(fn($e) => $e[0] === 'warn' && str_contains($e[1], 'chmod failed')))->toBeTrue()
        ->and(collect($events)->contains(fn($e) => $e[0] === 'line' && str_contains($e[1], 'finished')))->toBeTrue();

    @unlink($marker);
});

it('fails with a non-zero exit even though stderr was also written', function() {
    $caught = runWithStandInUpdater('fwrite(STDERR, "warn"); exit(3);');

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('3');
});

it('rejects an external process class that is not a Symfony Process', function(string $class) {
    expect(fn() => (new ProcessFactory($class))->invoke('echo 1;'))
        ->toThrow(RuntimeException::class, 'does not exist or is not a');
})->with(['missing' => ['No\\Such\\Class'], 'wrong type' => [stdClass::class]]);
