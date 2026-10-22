<?php

/** @noinspection PhpUnused */

namespace Pixelated\Streamline\Install;

use Composer\Script\Event;
use Exception;

class RequiredFunctionsCheck
{
    /**
     * The functions the update actually depends on: exec() runs composer, `artisan optimize:clear`
     * and `artisan migrate` inside the updater script, and proc_open() (via Symfony Process) spawns
     * that script in the first place. These are independently toggleable in disable_functions, so
     * each has to be checked in its own right.
     *
     * @var string[]
     */
    public const array REQUIRED_FUNCTIONS = ['exec', 'proc_open'];

    public static function run(Event $event): bool
    {
        $io = $event->getIO();

        $value = self::findUnavailableFunction();

        if ($value !== null) {
            $io->warning(
                "Streamline runs updates by executing external commands, so it needs PHP's " .
                implode('() and ', self::REQUIRED_FUNCTIONS) . "() functions.\n" .
                "You will not be able to use this package to update your application unless they are enabled.\n" .
                "Error: $value"
            );

            return false;
        }

        return true;
    }

    /**
     * Returns a description of the first unavailable function, or null when they are all usable.
     */
    public static function findUnavailableFunction(): ?string
    {
        // Check if the function is disabled via disable_functions in php.ini
        $disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (self::REQUIRED_FUNCTIONS as $function) {
            if (in_array($function, $disabledFunctions, true)) {
                return "$function() is listed in PHP's disable_functions() check. This means someone has deliberately disabled it, possibly a system administrator.";
            }

            if (!function_exists($function)) {
                return "The function_exists(\"$function\") check returns false";
            }
        }

        return self::canExecuteCommands();
    }

    private static function canExecuteCommands(): ?string
    {
        // Additional check to verify command execution actually works
        try {
            if (@exec('echo test') !== 'test') {
                return 'Unable to run the exec() function. It does not output as expected. This might be due to permissions or restrictions.';
            }
        } catch (Exception $e) {
            return 'Unable to execute system commands due to an exception: ' . $e->getMessage();
        }

        return null;
    }
}
