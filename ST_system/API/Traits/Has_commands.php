<?php

namespace ST_system\API\Traits;

trait Has_commands {
    private $command_handlers = [];

    final public function set_command(string $command, callable $handler) {
        $this->command_handlers[$command] = $handler;
    }

    final public function set_command_map(array $commands) {
        array_walk($commands, fn($handler, $command) => $this->set_command($command, $handler));
    }

    abstract protected function handle_response($response): array;
    abstract protected function handle_updates(): array;

    final protected function handle_input($response) {
        $args = $this->handle_response($response);

        $command_line = $args[0];
        unset($args[0]);
 
        if (trim($command_line) == '') return false;

        if (str_starts_with($command_line, '/')) {
            preg_match_all("/('[^']*'|\"[^\"]*\"|\S+)/", $command_line, $matches);

            $command_line = array_shift($matches[0]);

            $current = null;
            foreach ($matches[0] as $part)
                if (str_starts_with($part, '-')) {
                    $current = ltrim($part, '-');

                    if (!isset($args[$current]))
                        $args[$current] = null;

                } elseif ($current !== null) {
                    if ($args[$current] === null)
                        $args[$current] = $part;
                    elseif (is_array($args[$current]))
                        $args[$current][] = $part;
                    else
                        $args[$current] = [$args[$current], $part];
                }

        }
                
        return isset($this->command_handlers[$command_line])
            ? call_user_func($this->command_handlers[$command_line], $args, $response)
            : false;
    }

    final public function daemon(int $time_limit = 300) {
        if ($time_limit > 0) {
            set_time_limit($time_limit);
            $end_time = time() + $time_limit;
        } else {
            set_time_limit(0);
            $end_time = null;
        }
        
        while ($end_time === null || time() < $end_time)
            foreach ($this->handle_updates() as $update)
                $this->handle_input($update);
    }
    
}
