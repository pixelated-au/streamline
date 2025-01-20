<?php

use Pixelated\Streamline\Services\DeployableUpdaterStubTools;

it('should throw a RuntimeException when update files are found in the updater path', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = 'update';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('glob')
        ->once()
        ->with("$updaterPath/{$filePrefix}_*.php")
        ->andReturn(['update_123.php', 'update_456.php']);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Updater already appears to be running. If this is not intentional, please delete: update_123.php,update_456.php');

    $fileTools->confirmUpdateNotRunning();
});

it('should return the correct update file content when a custom stub exists', function () {
    $updaterPath       = '/path/to/updater';
    $filePrefix        = 'update';
    $customStubContent = 'Custom stub content';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('exists')->andReturn(true);
    File::shouldReceive('get')->andReturn($customStubContent);

    $result = $fileTools->getUpdatePhpFileStub();

    expect($result)->toBe($customStubContent);
});

it('should return the correct update config file content', function () {
    $updaterPath             = '/path/to/updater';
    $filePrefix              = 'update';
    $customStubConfigContent = 'Custom stub config content';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('exists')->andReturn(true);
    File::shouldReceive('get')->andReturn($customStubConfigContent);

    $result = $fileTools->getConfigClassStub();

    expect($result)->toBe($customStubConfigContent);
});

it('should correctly handle an empty file prefix in the constructor', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = '';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    expect($fileTools)->toBeInstanceOf(DeployableUpdaterStubTools::class);

    $deployPath = $fileTools->generateUpdaterFileDeploymentPath();

    expect($deployPath)->toStartWith($updaterPath . '/_')
        ->and($deployPath)->toEndWith('.php')
        ->and(Str::between($deployPath, $updaterPath . '/_', '.php'))->toHaveLength(20)
        ->and(Str::between($deployPath, $updaterPath . '/_', '.php'))->toMatch('/^[a-zA-Z0-9]+$/');
});

it('should generate a unique deploy path with the correct prefix and random string', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = 'update';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    $deployPath = $fileTools->generateUpdaterFileDeploymentPath();

    expect($deployPath)->toStartWith($updaterPath . '/' . $filePrefix . '_')
        ->and($deployPath)->toEndWith('.php')
        ->and(Str::between($deployPath, $updaterPath . '/' . $filePrefix . '_', '.php'))->toHaveLength(20)
        ->and(Str::between($deployPath, $updaterPath . '/' . $filePrefix . '_', '.php'))->toMatch('/^[a-zA-Z0-9]+$/');
});

it('should return the default update file content when no custom stub exists', function () {
    $updaterPath        = '/path/to/updater';
    $filePrefix         = 'update';
    $defaultStubContent = 'Default stub content';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('get')->andReturn($defaultStubContent);

    $result = $fileTools->getUpdatePhpFileStub();

    expect($result)->toBe($defaultStubContent);
});

it('should throw a RuntimeException when the updater path is empty', function () {
    $updaterPath = '';
    $filePrefix  = 'update';

    expect(fn() => new DeployableUpdaterStubTools($updaterPath, $filePrefix))
        ->toThrow(RuntimeException::class);
});

it('should use the correct file prefix when searching for existing update files', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = 'custom_prefix';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('glob')
        ->once()
        ->with("$updaterPath/{$filePrefix}_*.php")
        ->andReturn(['custom_prefix_123.php', 'custom_prefix_456.php']);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Updater already appears to be running. If this is not intentional, please delete: custom_prefix_123.php,custom_prefix_456.php');

    $fileTools->confirmUpdateNotRunning();
});

it('should correctly handle file system errors when checking for existing update files', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = 'update';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('glob')
        ->once()
        ->with("$updaterPath/{$filePrefix}_*.php")
        ->andThrow(new RuntimeException('File system error'));

    expect(fn() => $fileTools->confirmUpdateNotRunning())
        ->toThrow(RuntimeException::class, 'File system error');
});

it('should return an empty array when no update files are found in the updater path', function () {
    $updaterPath = '/path/to/updater';
    $filePrefix  = 'update';

    $fileTools = new DeployableUpdaterStubTools($updaterPath, $filePrefix);

    File::shouldReceive('glob')
        ->once()
        ->with("$updaterPath/{$filePrefix}_*.php")
        ->andReturn([]);

    expect(fn() => $fileTools->confirmUpdateNotRunning())->not->toThrow(RuntimeException::class);
});
