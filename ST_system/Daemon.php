<?php

namespace ST_system;

use ST_system\Config;
use ST_system\Main;
use ST_system\Traits\HasConfig;
use ST_system\Traits\HasAttributes;
use ST_system\Traits\HasEvents;

class Daemon {

    use HasAttributes;
    
    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'interval' => 0,
            'retries' => 3
        ];
    }

    use HasEvents;

    protected static function getReservedEvents(): array {
        return [
            'commit',
            'reload'
        ];
    }

    public string $pid;
    protected bool $running = false;

    private \Closure $initFn;
    private \Closure $runFn;

    private bool    $committed = false;
    private array   $checkpoints = [];

    final private function __construct() {
        $this->pid = Config::env('SUPERVISOR_PROCESS_NAME') ?: static::class.':'.getmypid();

        $this->initFn = fn($self) => $self->init($self);
        $this->runFn  = fn($self) => $self->run($self);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->running = false);
        pcntl_signal(SIGINT,  fn() => $this->running = false);
        pcntl_signal(SIGHUP,  fn() => $this->fire('reload'));

        register_shutdown_function(function () {
            if ($this->committed) return;
            $this->committed = true;
            $this->fire('commit');
        });
    }

    
    final public static function __callStatic(string $name, array $args) {
        $instance = new static();

        if ($name === 'init') {
            $fn = $args[0] ?? null;
            if ($fn) {
                $prev = $instance->initFn;
                $instance->initFn = function ($self) use ($prev, $fn) {
                    $prev($self);
                    $fn($self);
                };
            }
            return $instance;
        }

        return $instance->__call($name, $args);
    }

    
    final public function __call(string $name, array $args) {
        $fn = $args[0] ?? null;

        if ($name === 'run') {
            if ($fn) $this->runFn = $fn;
            $this->_init();
            $this->loop();
            return $this;
        }

        if (strncmp($name, 'on', 2) === 0 && strlen($name) > 2) {
            if ($fn) $this->on(lcfirst(substr($name, 2)), $fn);
            return $this;
        }

        throw new \BadMethodCallException("Method {$name} not found");
    }

    final protected function checkpoint(string $name, callable $cond, bool $once = true, int $interval = 0): void {
        static $last_checkpoint = null;

        if (isset($this->checkpoints[$name]))
            throw new \LogicException("Checkpoint '{$name}' is already registered.");
        if (in_array($name, static::getReservedEvents(), true))
            throw new \LogicException("Checkpoint name '{$name}' conflicts with a reserved event.");

        $this->checkpoints[$name] = ['cond' => $cond, 'once' => $once, 'interval' => $interval, 'last_check' => 0, 'reached' => false, 'prev' => $last_checkpoint];
        $last_checkpoint = $name;
    }

    
    final protected function goal(string $name, &...$params): void {
        if (!isset($this->checkpoints[$name]))
            throw new \LogicException("Checkpoint '{$name}' is not registered.");

        $cp = &$this->checkpoints[$name];

        if ($cp['once'] && $cp['reached']) return;
        if ($cp['prev'] !== null && !$this->checkpoints[$cp['prev']]['reached']) return;

        if ($cp['interval'] > 0) {
            $now = Main::timestamp();
            if ($now - $cp['last_check'] < $cp['interval']) return;
            $cp['last_check'] = $now;
        }

        if (!call_user_func_array($cp['cond'], $params)) return;

        $cp['reached'] = true;
        $this->fire($name, ...$params);
    }

    protected function init(): void {}
    protected function run(): void {}

    private function _init(): void { ($this->initFn)($this); }
    private function _run(): void  { ($this->runFn)($this); }

    private function loop(): void {
        $this->running = true;
        $retries = 0;

        while ($this->running) {
            try {
                $this->_run();
                $retries = 0;
            } catch (\Throwable $e) {
                $retries++;
                $this->fire('error', $e, $retries);
                if ($retries >= static::config('retries')) {
                    $this->running = false;
                }
            }

            if (static::config('interval') > 0) sleep(static::config('interval'));
            pcntl_signal_dispatch();
        }
    }
}
