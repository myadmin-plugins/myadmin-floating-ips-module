<?php

declare(strict_types=1);

/**
 * Stand-ins for the MyAdmin framework services that src/Plugin.php calls out to.
 *
 * MyAdmin itself is not a dependency of this package, so these stubs are what make
 * it possible to register the plugin's hooks on a real event dispatcher and invoke
 * them inside the test suite. Everything observable is recorded on FrameworkSpy.
 */

namespace MyAdmin {
    use Detain\MyAdminFloatingIps\Tests\Support\FrameworkSpy;

    class App
    {
        /**
         * The service-type id that get_service_define('FLOATING_IPS') resolves to in
         * tests. Handlers guard on it, so tests use this constant to drive the guard.
         */
        public const FLOATING_IPS_SERVICE_TYPE = 1100;

        /**
         * @param  string $service
         * @return int
         */
        public static function getServiceDefine($service)
        {
            return $service === 'FLOATING_IPS' ? self::FLOATING_IPS_SERVICE_TYPE : -1;
        }

        /**
         * @return object
         */
        public static function history()
        {
            return new class () {
                /**
                 * @param  mixed ...$args
                 * @return void
                 */
                public function add(...$args)
                {
                    FrameworkSpy::$history[] = $args;
                }
            };
        }
    }
}

namespace Detain\Sshwitch {
    use Detain\MyAdminFloatingIps\Tests\Support\FrameworkSpy;

    /**
     * Records the command batches the plugin would have pushed to a network switch
     * over SSH instead of connecting to one.
     */
    class Sshwitch
    {
        /**
         * @var array<int, string>
         */
        public static $output = [];

        /**
         * @param  string             $switchIp
         * @param  array<int, string> $cmds
         * @return array<int, string>
         */
        public static function run($switchIp, $cmds)
        {
            FrameworkSpy::$switchCommands[] = ['ip' => $switchIp, 'cmds' => $cmds];
            self::$output = ['raw switch output'];
            return ['switch accepted ' . count($cmds) . ' commands'];
        }
    }
}

namespace {
    use Detain\MyAdminFloatingIps\Tests\Support\FrameworkSpy;

    if (!function_exists('myadmin_log')) {
        /**
         * @param  mixed ...$args
         * @return void
         */
        function myadmin_log(...$args)
        {
            FrameworkSpy::$logs[] = $args;
        }
    }

    if (!function_exists('run_event')) {
        /**
         * Only 'get_service_types' is consumed by this plugin.
         *
         * @param  string $event
         * @param  mixed  $data
         * @param  string $module
         * @return mixed
         */
        function run_event($event, $data = false, $module = '')
        {
            if ($event === 'get_service_types') {
                return FrameworkSpy::$serviceTypes;
            }
            return $data;
        }
    }

    if (!function_exists('chatNotify')) {
        /**
         * @param  mixed ...$args
         * @return void
         */
        function chatNotify(...$args)
        {
            FrameworkSpy::$notifications[] = $args;
        }
    }

    // gettext is not guaranteed to be enabled on every CI leg.
    if (!function_exists('_')) {
        /**
         * @param  string $string
         * @return string
         */
        function _($string)
        {
            return $string;
        }
    }
}
