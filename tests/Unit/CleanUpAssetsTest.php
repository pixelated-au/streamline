<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Pixelated\Streamline\Facades\CleanUpAssets;

it('can clean up assets with multiple revisions', function() {
    Storage::fake();

    storeFiles([
        'app.123abc.js'         => 1,
        'app.456def_keep.js'    => 2,
        'app.789ghi_keep.js'    => 2,
        'app.101jkl.js'         => 1,
        'style.111aaa.css'      => 1,
        'style.222bbb_keep.css' => 2,
        'style.333ccc.css'      => 1,
        'style.444ddd_keep.css' => 2,
    ]);

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.web_assets_build_num_revisions', 2);
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run();

    Storage::assertExists('app.456def_keep.js');
    Storage::assertExists('app.789ghi_keep.js');
    Storage::assertExists('style.222bbb_keep.css');
    Storage::assertExists('style.444ddd_keep.css');
    Storage::assertMissing('app.123abc.js');
    Storage::assertMissing('app.101jkl.js');
    Storage::assertMissing('style.111aaa.css');
    Storage::assertMissing('style.333ccc.css');
});

it('handles empty asset directory', function() {
    Storage::fake();

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.web_assets_build_num_revisions', 2);
    Config::set('streamline.laravel_public_disk_name', '');

    Storage::assertDirectoryEmpty(Storage::getConfig()['root']);

    CleanUpAssets::run();

    Storage::assertDirectoryEmpty(Storage::getConfig()['root']);
});

it('ignores files with non-matching extensions', function() {
    Storage::fake();

    storeFiles([
        'app.123abc.foo'        => 1,
        'app.456def_keep.js'    => 2,
        'app.789ghi_keep.js'    => 2,
        'app.101jkl.js'         => 1,
        'style.111aaa.css'      => 1,
        'style.222bbb_keep.css' => 2,
        'style.333ccc.bar'      => 1,
        'style.444ddd_keep.css' => 1,
    ]);

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.web_assets_build_num_revisions', 2);
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run();

    Storage::assertExists('app.123abc.foo');
    Storage::assertExists('style.333ccc.bar');

    Storage::assertExists('app.789ghi_keep.js');
    Storage::assertExists('style.222bbb_keep.css');

    Storage::assertMissing('app.456def.js');
    Storage::assertMissing('style.444ddd.css');

    Storage::assertMissing('app.101jkl.js');
    Storage::assertMissing('style.111aaa.css');
});

it('throws exception when storage operation fails', function() {
    Config::set('streamline.laravel_public_disk_name', 'public');

    Storage::purge('public');
    $mock = Mockery::mock(Filesystem::class);
    $mock->shouldReceive('delete')->andReturnFalse();
    $mock->shouldReceive('files')->andReturn(['app.123abc.js', 'style.111aaa.css']);
    $mock->shouldReceive('lastModified')->andReturn('12345678');
    $mock->shouldReceive('allFiles')->andReturn([]);
    $mock->shouldReceive('exists')->andReturnFalse();

    Storage::set('public', $mock);

    expect(static fn() => CleanUpAssets::run())
        ->toThrow(
            RuntimeException::class,
            'Error: Failed to clean out redundant front-end build assets. Could not execute the cleanup command.'
        );
});

it('overrides the number of revisions in config allowing only 1', function() {
    Storage::fake();

    storeFiles([
        'app.123abc.js'         => 1,
        'app.456def_keep.js'    => 4,
        'app.789ghi.js'         => 3,
        'app.101jkl.js'         => 2,
        'style.111aaa.css'      => 1,
        'style.222bbb_keep.css' => 4,
        'style.333ccc.css'      => 3,
        'style.444ddd.css'      => 2,
    ]);

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.web_assets_build_num_revisions', 2);
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run(1);

    Storage::assertExists('app.456def_keep.js');
    Storage::assertExists('style.222bbb_keep.css');

    Storage::assertMissing('app.789ghi.js');
    Storage::assertMissing('style.444ddd.css');
    Storage::assertMissing('app.123abc.js');
    Storage::assertMissing('app.101jkl.js');
    Storage::assertMissing('style.111aaa.css');
    Storage::assertMissing('style.333ccc.css');
});

it('overrides the number of revisions in config allowing only 3', function() {
    Storage::fake();

    storeFiles([
        'app.123abc_keep.js'    => 2,
        'app.456def_keep.js'    => 4,
        'app.789ghi_keep.js'    => 3,
        'app.101jkl.js'         => 1,
        'style.111aaa.css'      => 1,
        'style.222bbb_keep.css' => 4,
        'style.333ccc_keep.css' => 3,
        'style.444ddd_keep.css' => 2,
    ]);

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run(3);

    Storage::assertExists('app.456def_keep.js');
    Storage::assertExists('style.222bbb_keep.css');
    Storage::assertExists('app.789ghi_keep.js');
    Storage::assertExists('style.444ddd_keep.css');
    Storage::assertExists('app.123abc_keep.js');
    Storage::assertExists('style.333ccc_keep.css');

    Storage::assertMissing('app.101jkl.js');
    Storage::assertMissing('style.111aaa.css');
});

it('files are not deleted if there are less files available than the number of revisions', function() {
    Storage::fake();

    storeFiles([
        'app.123abc_keep.js'    => 2,
        'app.456def_keep.js'    => 4,
        'app.789ghi_keep.js'    => 3,
        'app.101jkl_keep.js'    => 1,
        'style.111aaa_keep.css' => 5,
        'style.222bbb_keep.css' => 4,
        'style.333ccc_keep.css' => 3,
        'style.444ddd_keep.css' => 2,
        'style.555eee.css'      => 1, // this one should be deleted
    ]);

    Config::set('streamline.build_dir', Storage::getConfig()['root']);
    Config::set('streamline.laravel_build_dir_name', '');
    Config::set('streamline.laravel_asset_dir_name', '');
    Config::set('streamline.laravel_public_disk_name', '');
    Config::set('streamline.web_assets_build_num_revisions', 4);

    CleanUpAssets::run();

    Storage::assertExists('style.111aaa_keep.css');
    Storage::assertExists('style.222bbb_keep.css');
    Storage::assertExists('style.444ddd_keep.css');
    Storage::assertExists('style.333ccc_keep.css');

    Storage::assertExists('app.123abc_keep.js');
    Storage::assertExists('app.456def_keep.js');
    Storage::assertExists('app.789ghi_keep.js');
    Storage::assertExists('app.101jkl_keep.js');

    Storage::assertMissing('style.555eee.css');
});

/** @param  array<string, int>  $files */
it('never deletes assets referenced by the current build manifest, even when they have the oldest mtime', function() {
    Storage::fake();

    // The current build is extracted with an old mtime while carried-forward assets get copied (new mtime)
    storeFiles([
        'build/assets/app.123abc.js'     => 1, // current build (oldest mtime)
        'build/assets/app.456def.js'     => 3,
        'build/assets/app.789ghi.js'     => 4,
        'build/assets/app.101jkl.js'     => 5,
        'build/assets/app.123abc.js.map' => 1,
        'build/assets/style.111aaa.css'  => 1, // current, referenced via the css key
        'build/assets/style.222bbb.css'  => 3,
        'build/assets/style.333ccc.css'  => 4,
        'build/manifest.json'            => 1,
    ]);
    Storage::put('build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app.123abc.js', 'css' => ['assets/style.111aaa.css']],
    ]));

    Config::set('streamline.laravel_build_dir_name', 'build');
    Config::set('streamline.laravel_asset_dir_name', 'assets');
    Config::set('streamline.web_assets_build_num_revisions', 1);
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run();

    Storage::assertExists('build/assets/app.123abc.js');
    Storage::assertExists('build/assets/style.111aaa.css');
    Storage::assertMissing('build/assets/app.456def.js');
    Storage::assertMissing('build/assets/style.222bbb.css');
});

it('never deletes assets referenced by the .vite/manifest.json', function() {
    storeFiles([
        'build/assets/app.123abc.js' => 1,
        'build/assets/app.456def.js' => 3,
    ]);
    Storage::put('build/.vite/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app.123abc.js'],
    ]));

    Config::set('streamline.laravel_build_dir_name', 'build');
    Config::set('streamline.laravel_asset_dir_name', 'assets');
    Config::set('streamline.web_assets_build_num_revisions', 0);
    Config::set('streamline.laravel_public_disk_name', '');

    CleanUpAssets::run();

    Storage::assertExists('build/assets/app.123abc.js');
    Storage::assertMissing('build/assets/app.456def.js');
});

function storeFiles(array $files): void
{
    Storage::fake();

    foreach ($files as $file => $offset) {
        test()->travelTo(now()->subYear()->addDays($offset));
        Storage::put($file, 'content');
        touch(Storage::path($file), now()->timestamp);
        test()->travelBack();
    }
}
