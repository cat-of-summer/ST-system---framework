<?php

namespace ST_system\Console;

abstract class Command {

    protected static string $signature = '';

    private array $arguments = [];
    private array $options   = [];

    final public static function getSignature(): string {
        return static::$signature;
    }

    /** Имя команды - первое слово сигнатуры до пробела или `{`. */
    final public static function getName(): string {
        return preg_match('/^\s*([^\s{]+)/', static::$signature, $m) ? $m[1] : '';
    }

    final public function __construct(array $positional = [], array $rawOptions = []) {
        [$argDefs, $optDefs] = static::parseSignature(static::getSignature());
        $this->arguments = static::resolveArguments($positional, $argDefs);
        $this->options   = static::resolveOptions($rawOptions, $optDefs);
    }

    /** @return static */
    final public static function fetch(...$args) { return new static(...$args); }

    /**
     * Разобранная сигнатура: позиционные аргументы и опции.
     *
     * @return array{arguments: list<array>, options: array<string, array>}
     */
    final public static function definition(): array {
        [$arguments, $options] = static::parseSignature(static::getSignature());

        return ['arguments' => $arguments, 'options' => $options];
    }

    /**
     * Копия команды с другими значениями, сама команда не меняется: один объект живёт долго и
     * запускается много раз, например в сервере.
     *
     * @return static
     */
    final public function withInput(array $positional = [], array $rawOptions = []) {
        [$argDefs, $optDefs] = static::parseSignature(static::getSignature());

        $copy = clone $this;
        $copy->arguments = static::resolveArguments($positional, $argDefs);
        $copy->options   = static::resolveOptions($rawOptions, $optDefs);

        return $copy;
    }

    /**
     * Не передан обязательный аргумент. В CLI это конец процесса; там, где процесс живёт дальше,
     * подкласс переопределяет метод и решает сам. После возврата аргумент получает значение null.
     */
    protected static function missingArgument(string $name): void {
        fwrite(STDERR, "Missing required argument: {$name}" . PHP_EOL);
        exit(1);
    }

    /**
     * Возвращённый int становится кодом выхода процесса, всё остальное — 0.
     */
    abstract public function handle();

    protected function line(string $text): void {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    protected function error(string $text): void {
        fwrite(STDERR, $text . PHP_EOL);
    }

    /**
     * Вопросы задаются только при живом терминале: с -n/--no-interaction или при
     * перенаправленном STDIN ask()/confirm()/secret() сразу отдают значение по умолчанию.
     */
    protected function isInteractive(): bool {
        return empty($this->options['no-interaction']) && stream_isatty(STDIN);
    }

    protected function ask(string $question, ?string $default = null): ?string {
        if (!$this->isInteractive()) return $default;

        fwrite(STDOUT, $question . ($default !== null ? " [{$default}]" : '') . ': ');
        $answer = fgets(STDIN);
        $answer = $answer === false ? '' : trim($answer);

        return $answer !== '' ? $answer : $default;
    }

    protected function confirm(string $question, bool $default = false): bool {
        $answer = $this->ask($question . ($default ? ' (Y/n)' : ' (y/N)'));
        if ($answer === null) return $default;

        return in_array(mb_strtolower($answer), ['y', 'yes', 'д', 'да'], true);
    }

    protected function secret(string $question): ?string {
        if (!$this->isInteractive()) return null;

        $hide = DIRECTORY_SEPARATOR === '/' && trim((string)shell_exec('stty -g 2>/dev/null')) !== '';

        fwrite(STDOUT, $question . ': ');
        if ($hide) shell_exec('stty -echo');
        $answer = fgets(STDIN);
        if ($hide) { shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); }

        $answer = $answer === false ? '' : rtrim($answer, "\r\n");

        return $answer !== '' ? $answer : null;
    }

    /**
     * Содержимое, переданное в команду пайпом или перенаправлением (`cat x | php cli cmd`).
     * При вводе с терминала — пустая строка, чтобы команда не повисла в ожидании.
     */
    protected function stdin(): string {
        static $cache = null;

        return $cache ??= stream_isatty(STDIN) ? '' : (string)stream_get_contents(STDIN);
    }

    
    protected function option(string $key = '', $default = null) {
        return $key === '' ? $this->options : ($this->options[$key] ?? $default);
    }

    
    protected function argument(string $key = '', $default = null) {
        return $key === '' ? $this->arguments : ($this->arguments[$key] ?? $default);
    }

    
    private static function parseSignature(string $signature): array {
        preg_match_all('/\{([^}]+)\}/', $signature, $tokens);

        $argDefs = [];
        $optDefs = [];

        foreach ($tokens[1] as $token) {
            $token = trim($token);

            if (strpos($token, '--') === 0) {
                $inner = substr($token, 2);
                $alias = null;

                if (strpos($inner, '|') !== false) {
                    [$alias, $inner] = explode('|', $inner, 2);
                }

                if (strpos($inner, '=') !== false) {
                    [$name, $default] = explode('=', $inner, 2);
                    $optDefs[$name] = [
                        'name'    => $name,
                        'alias'   => $alias,
                        'flag'    => false,
                        'default' => $default === '' ? null : $default,
                    ];
                } else {
                    $optDefs[$inner] = [
                        'name'    => $inner,
                        'alias'   => $alias,
                        'flag'    => true,
                        'default' => false,
                    ];
                }
            } else {
                // {text*} и {text?*}: аргумент забирает остаток позиционных одной строкой
                $rest = substr($token, -1) === '*';
                if ($rest) {
                    $token = substr($token, 0, -1);
                }
                if (substr($token, -1) === '?') {
                    $name = substr($token, 0, -1);
                    $argDefs[] = ['name' => $name, 'required' => false, 'default' => null, 'rest' => $rest];
                } elseif (strpos($token, '=') !== false) {
                    [$name, $default] = explode('=', $token, 2);
                    $argDefs[] = ['name' => $name, 'required' => false, 'default' => $default, 'rest' => $rest];
                } else {
                    $argDefs[] = ['name' => $token, 'required' => true, 'default' => null, 'rest' => $rest];
                }
            }
        }

        return [$argDefs, $optDefs];
    }

    private static function resolveArguments(array $positional, array $argDefs): array {
        $result = [];

        foreach ($argDefs as $i => $def) {
            if (array_key_exists($i, $positional)) {
                $result[$def['name']] = $def['rest'] ? implode(' ', array_slice($positional, $i)) : $positional[$i];
            } elseif ($def['required']) {
                static::missingArgument($def['name']);
                $result[$def['name']] = null;
            } else {
                $result[$def['name']] = $def['default'];
            }
        }

        return $result;
    }

    private static function resolveOptions(array $rawOptions, array $optDefs): array {
        $aliases = array_column($optDefs, 'alias');
        $optDefs += ['no-interaction' => [
            'name'    => 'no-interaction',
            'alias'   => in_array('n', $aliases, true) ? null : 'n',
            'flag'    => true,
            'default' => false,
        ]];

        $aliasMap = [];
        foreach ($optDefs as $def) {
            if ($def['alias'] !== null) {
                $aliasMap[$def['alias']] = $def['name'];
            }
        }

        $normalized = [];
        foreach ($rawOptions as $key => $value) {
            $normalized[$aliasMap[$key] ?? $key] = $value;
        }

        $result = [];
        foreach ($optDefs as $name => $def) {
            $result[$name] = $normalized[$name] ?? $def['default'];
        }
        foreach ($normalized as $key => $value) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
