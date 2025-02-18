<?php

/** @noinspection PhpUnused */

namespace Pixelated\Streamline\Install;

use Composer\Script\Event;
use Exception;

class RequiredFunctionsCheck
{
    public static function run(Event $event): bool
    {
        $io = $event->getIO();

        $value = self::isSystemFunctionEnabled();
        if ($value !== null) {
            $io->warning(
                "The PHP system() (https://www.php.net/system) function is disabled on your PHP configuration.\n" .
                "You will not be able to use this package to update your application unless that is enabled.\n" .
                "Error: $value"
            );

            return false;
        }

        return true;
    }

    public static function isSystemFunctionEnabled(): ?string
    {
        // Check if system() is disabled via disable_functions in php.ini
        $disabledFunctions = explode(',', ini_get('disable_functions'));

        if (in_array('system', $disabledFunctions, true)) {
            return "function is listed in PHP's disable_functions() check. This means someone has deliberately disabled it, possibly a system administrator.";
        }

        // Check if function exists and is not in disabled functions
        if (! function_exists('system')) {
            return 'The function_exists("system") check returns false';
        }

        $value = self::canExecuteSystemCommands();
        /** @noinspection NullCoalescingOperatorCanBeUsedInspection */
        if ($value !== null) {
            return $value;
        }

        return null;
    }

    private static function canExecuteSystemCommands(): ?string
    {
        // Additional check to verify system command execution
        try {
            if (@system('echo test') !== 'test') {
                return 'Unable to run the system() function. It does not output as expected. This might be due to permissions or restrictions.';
            }
        } catch (Exception $e) {
            return 'Unable to execute system commands due to an exception: ' . $e->getMessage();
        }

        return null;
    }
}
