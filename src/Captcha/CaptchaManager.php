<?php

namespace ST_system\Captcha;

use ST_system\Traits\HasConfig;
use ST_system\Captcha\CaptchaDriver;
use ST_system\Cache\CacheManager as Cache;
use ST_system\Main;
use ST_system\View;

final class CaptchaManager {

    use HasConfig {
        setConfig as private traitSetConfig;
    }

    protected static function getDefaultConfig(): array {
        $drivers = [
            'invisible' => \ST_system\Captcha\Drivers\InvisibleCaptchaDriver::class,
            'checkbox'  => \ST_system\Captcha\Drivers\CheckboxCaptchaDriver::class,
            'swipe'     => \ST_system\Captcha\Drivers\SwipeCaptchaDriver::class,
            'text'      => \ST_system\Captcha\Drivers\TextCaptchaDriver::class,
            'smart'     => \ST_system\Captcha\Drivers\SmartCaptchaDriver::class,
        ];

        return [
            'default' => [
                'ttl'          => 300,
                'attempts'     => 3,
                'min_score'    => 0.5,
                'field_prefix' => 'st-captcha',
                'salt'         => '',
                'cache'        => [
                    'driver' => 'filesystem',
                    'dir'    => Main::glue([Cache::config('default.dir'), 'Captcha'], '/'),
                ],
                'behavior' => [
                    'signals' => Behavior::GROUPS,
                    'basic'   => ['min_solve' => 700],
                    'pow'     => ['difficulty' => 15],
                    'weights' => [
                        'basic'       => 0.4,
                        'env'         => 0.3,
                        'pow'         => 0.15,
                        'fingerprint' => 0.15,
                    ],
                ],
            ],
            'drivers' => [
                'default'   => $drivers['invisible'],
                'available' => $drivers,
            ],
        ];
    }

    public static function registerViewEvents(): void {
        static $initialized = false;
        if ($initialized) return;
        $initialized = true;

        View::on('render_open', static function () { CaptchaDriver::resetEmitted(); });
    }

    public static function markLive(): void {
        if (class_exists(View::class, false)) View::live();
    }

    public static function setConfig(array $config = []): void {
        $flat = Main::dotFlatten($config);

        $managerConfig = [];
        $driverConfigs = [];

        foreach ($flat as $key => $value) {
            if (strncmp($key, 'drivers.', 8) === 0) {
                $rest   = substr($key, strlen('drivers.'));
                $dotPos = strpos($rest, '.');

                if ($dotPos !== false) {
                    $driverName = substr($rest, 0, $dotPos);
                    $subKey     = substr($rest, $dotPos + 1);

                    if ($driverName !== 'default' && $driverName !== 'available') {
                        $driverClass = static::config('drivers.available.'.$driverName) ?: $driverName;
                        $driverConfigs[$driverClass][$subKey] = $value;
                        continue;
                    }
                }
            }
            $managerConfig[$key] = $value;
        }

        if ($managerConfig) {
            static::traitSetConfig($managerConfig);
        }

        foreach ($driverConfigs as $driverClass => $cfg) {
            if (class_exists($driverClass)) {
                $driverClass::setConfig($cfg);
            }
        }
    }

    private CaptchaDriver $driver;

    public function __construct($key, array $config = []) {
        if ($key instanceof CaptchaDriver) {
            $this->driver = $key;
            return;
        }

        $explicit  = array_key_exists('driver', $config);
        $requested = $explicit ? $config['driver'] : static::config('drivers.default');
        unset($config['driver']);

        $class = static::config('drivers.available.'.$requested) ?: $requested;

        $this->driver = static::makeDriver($class, $key, $config);

        if ($this->driver->isAvailable()) return;

        throw new \RuntimeException(
            "Captcha driver '{$requested}' ({$class}), "
            .($explicit ? 'requested explicitly' : 'taken from drivers.default').', is not available'
        );
    }

    private static function makeDriver($class, $key, array $config): CaptchaDriver {
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, CaptchaDriver::class))
            throw new \InvalidArgumentException("Captcha driver must be a subclass of ".CaptchaDriver::class);

        return new $class($key, $config);
    }

    public static function __callStatic(string $name, array $args) {
        if ($name === 'make')
            return static::make(...$args);

        $available = (array)static::config('drivers.available');

        if (isset($available[$name])) {
            $key    = array_shift($args);
            $config = (array)array_shift($args);
            $config['driver'] = $name;

            return static::make($key, $config);
        }

        throw new \BadMethodCallException("Method {$name} not found");
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'make':
                $key      = array_shift($args) ?: $this->driver->raw_key;
                $override = (array)array_shift($args);

                return new static($this->driver->spawn($key, $override));
        }

        return $this->driver->{$name}(...$args);
    }

    public function __get(string $name) {
        if ($name === 'driver') return $this->driver;

        return $this->driver->{$name};
    }

    public function __isset(string $name): bool {
        if ($name === 'driver') return true;

        return isset($this->driver->{$name});
    }

    private static function make(...$args): self { return new static(...$args); }
}
