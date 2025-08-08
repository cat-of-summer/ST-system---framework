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

    abstract protected function handle_response($response): string;

    final public function handle_input($response, array $PARAMS = []) {
        $command_line = $this->handle_response($response);

        if (!$command_line) return false;

        preg_match_all("/('[^']*'|\"[^\"]*\"|\S+)/", $command_line, $matches);
        $parts = $matches[0];

        $command = ltrim(array_shift($parts), '/');

        $line_params = [];

        $current = null;
        foreach ($parts as $part)
            if (str_starts_with($part, '-')) {
                $current = ltrim($part, '-');

                if (!isset($line_params[$current]))
                    $line_params[$current] = null;

            } elseif ($current !== null) {
                if ($line_params[$current] === null)
                    $line_params[$current] = $part;
                elseif (is_array($line_params[$current]))
                    $line_params[$current][] = $part;
                else
                    $line_params[$current] = [$line_params[$current], $part];
            }
        
        return isset($this->command_handlers[$command])
            ? call_user_func($this->command_handlers[$command], array_merge($PARAMS, $line_params), $response)
            : false;
    }
    
}
