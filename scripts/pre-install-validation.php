<?php

function findLaravelBasePath(): string
{
    // Start from the directory containing the composer.json that executed this script
    $dir = dirname(__DIR__, 2);

    // Check for .env file
    if (file_exists($dir . '/.env')) {
        // Read .env file
        $envContents = file_get_contents($dir . '/.env');

        // Check if APP_BASE_PATH is set
        if (preg_match('/^APP_BASE_PATH=(.*)$/m', $envContents, $matches)) {
            $appBasePath = trim($matches[1]);
            if (!empty($appBasePath)) {
                return $appBasePath;
            }
        }
    }

    // If .env doesn't exist or APP_BASE_PATH is not set, use the parent directory
    return $dir;
}

$laravelBasePath = findLaravelBasePath();

if (!is_dir($laravelBasePath)) {
    echo "Error: The determined Laravel base path ($laravelBasePath) is not a valid directory.\n";
    exit(1);
}

if (!is_writable($laravelBasePath)) {
    echo "Error: The Laravel installation directory ($laravelBasePath) is not writable.\n";
    echo "Please ensure you have the necessary permissions to write to this directory.\n";
    exit(1);
}

echo "The Laravel installation directory ($laravelBasePath) is writable.\n";
echo "Installation can proceed.\n";
exit(0);
