<?php

use Illuminate\Support\Facades\File;

it('copies the configuration file', function() {
    File::delete(config_path('streamline.php'));
    File::delete(app_path('.streamline'));

    $this->assertTrue(File::missing(config_path('streamline.php')));
    $this->assertTrue(File::missing(app_path('.streamline')));

    $this->withoutMockingConsoleOutput()
        ->artisan('streamline:install'); // Spatie Package Tools function

    $this->assertTrue(File::exists(config_path('streamline.php')));
})
    ->after(function() {
        File::delete(config_path('streamline.php'));
    });
