<?php

declare(strict_types=1);

namespace Detain\MyAdminFloatingIps\Tests\Support;

/**
 * Collects the side effects the Plugin's hook handlers push out into the MyAdmin
 * framework: log lines, history entries, switch command batches and chat notices.
 *
 * MyAdmin is not a dependency of this package, so this is what lets a test assert
 * on what a handler actually did instead of on how its source happens to be written.
 */
final class FrameworkSpy
{
    /**
     * Positional argument lists of every myadmin_log() call.
     *
     * @var array<int, array<int, mixed>>
     */
    public static $logs = [];

    /**
     * Positional argument lists of every \MyAdmin\App::history()->add() call.
     *
     * @var array<int, array<int, mixed>>
     */
    public static $history = [];

    /**
     * Command batches handed to the switch, as ['ip' => ..., 'cmds' => [...]].
     *
     * @var array<int, array<string, mixed>>
     */
    public static $switchCommands = [];

    /**
     * Messages passed to chatNotify().
     *
     * @var array<int, array<int, mixed>>
     */
    public static $notifications = [];

    /**
     * Service-type rows run_event('get_service_types') hands back, keyed by type id.
     *
     * @var array<int|string, array<string, mixed>>
     */
    public static $serviceTypes = [];

    /**
     * Forget everything recorded so far. Call this at the start of any test that
     * asserts on the recorded calls.
     */
    public static function reset(): void
    {
        self::$logs = [];
        self::$history = [];
        self::$switchCommands = [];
        self::$notifications = [];
        self::$serviceTypes = [];
        DbDouble::reset();
    }

    /**
     * Log calls made at the given level ('info', 'error', ...).
     *
     * @return array<int, array<int, mixed>>
     */
    public static function logsWithLevel(string $level): array
    {
        return array_values(array_filter(self::$logs, static function (array $log) use ($level) {
            return isset($log[1]) && $log[1] === $level;
        }));
    }
}
