<?php

namespace ST_system\Captcha\Drivers\Game;

use ST_system\Captcha\CaptchaDriver;
use ST_system\Storage\File;

/**
 * Капча-мини-игра. Клиент присылает не результат, а лог вводов `{t, log: [[tick, code, value?]]}`;
 * сервер переигрывает партию своей копией игры (Simulation) по seed, который сам выдал, и
 * засчитывает, если набрано goal реакций (по умолчанию — за одну жизнь). Подмена JS на странице
 * поэтому ничего не даёт: счёт ведёт PHP.
 *
 * Цель браузеру не отдаётся. Он ставит галочку и присылает лог, когда данных достаточно
 * (`collect`: время и число действий с намерением) или вышло `max_time`; проигрыш в игре капчу не
 * проваливает — после смерти игрок начинает заново, статистика копится.
 *
 * Запасной путь (fallback): цель не набрана, но игрок играл достаточно долго и его действия были
 * ответом на ситуацию — тоже зачёт, с повышенным порогом поведенческого скоринга. Каким путём
 * пройдено, видно в `via` (`goal` | `fallback`).
 *
 * Игра — папка рядом с драйвером: Simulation на PHP и npm-проект, собранный в dist/game.min.js.
 */
abstract class GameCaptchaDriver extends CaptchaDriver {

    private const MAX_CODE      = 15;
    private const MAX_VALUE     = 100000;
    private const MAX_PER_TICK  = 8;

    /** Класс Simulation этой игры. */
    abstract protected static function simulation(): string;

    /** Тиков симуляции в секунду; константа игры, в JS — та же. */
    abstract protected static function tps(): int;

    abstract protected static function defaultGoal(): int;

    /** Доля осмысленных действий, без которой цель не засчитывается: набранное вслепую — не в счёт. */
    protected static function defaultGoalRatio(): float {
        return 0.6;
    }

    /** Высота полосы HUD сверху холста — та же, что HUD в assets/engine/src/driver.js. */
    public const HUD = 20;

    /**
     * Поле игры под холст w × h: параметры, которые от размера зависят (сетка, ширина мира),
     * — обеим копиям игры, чтобы серверная играла в то же поле. Игровая зона — w × (h − HUD).
     */
    protected static function layout(int $w, int $h): array {
        return [];
    }

    /** Целое x в пределах [min, max]. */
    final protected static function clamp(float $x, int $min, int $max): int {
        return max($min, min($max, (int)round($x)));
    }

    private const FALLBACK = ['time' => 8, 'actions' => 10, 'ratio' => 0.6, 'score' => 0.7];

    /**
     * Пороги запасного зачёта: секунды игры, действия с намерением, доля осмысленных среди них,
     * поведенческий скоринг. Время и число действий — ещё и порог сбора данных, на котором браузер
     * отдаёт лог. Пустой массив — у игры запасного пути нет, пока его не включат в конфиге.
     */
    protected static function defaultFallback(): array {
        return self::FALLBACK;
    }

    /** Секунд на игру: дальше браузер отдаёт то, что набрано. */
    protected static function defaultMaxTime(): int {
        return 20;
    }

    /**
     * Подсказка по управлению на стартовом экране: `keys` — с клавиатурой, `touch` — на телефоне.
     * Переопределяется ключом конфига `hint` (другой язык, свои слова).
     */
    protected static function defaultHint(): array {
        return ['keys' => '', 'touch' => ''];
    }

    /** Параметры партии для обеих копий игры. */
    protected function params(): array {
        return ['per_life' => (bool)$this->attributes['per_life']];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(static::baseConfig(), [
            'goal'        => static::defaultGoal(),
            'goal_ratio'  => static::defaultGoalRatio(),
            'per_life'    => true,
            'fallback'    => static::defaultFallback(),
            'size'        => [320, 200],
            'hint'        => static::defaultHint(),
            'big_button'  => 'left',
            'max_time'    => static::defaultMaxTime(),
            'max_events'  => 4000,
            'clock_slack' => 2,
        ]);
    }

    protected function __init(array $config): void {
        $this->attributes['via']       = '';
        $this->attributes['via_score'] = 0.0;
        $this->attributes['per_life']   = true;
        $this->attributes['goal_ratio'] = (int)round(static::defaultGoalRatio() * 100);
        $this->attributes['fallback']   = self::resolveFallback(static::defaultFallback());
        $this->attributes['size']       = [320, 200];
        $this->attributes['hint']       = static::defaultHint();
        $this->attributes['big_button'] = 'left';

        $this->applyGame($config);
    }

    protected function __rebind(array $override): void {
        $this->applyGame($override);
    }

    private function applyGame(array $config): void {
        if (isset($config['goal']))        $this->attributes['goal']        = max(1,   min(50,    (int)$config['goal']));
        if (isset($config['size'])) {
            $size = array_values((array)$config['size']);
            $this->attributes['size'] = [
                max(200, min(960, (int)($size[0] ?? 320))),
                max(140, min(960, (int)($size[1] ?? 200))),
            ];
        }
        if (isset($config['big_button'])) $this->attributes['big_button'] = $config['big_button'] === 'right' ? 'right' : 'left';
        if (isset($config['hint']) && is_array($config['hint']))
            $this->attributes['hint'] = array_map('strval', array_intersect_key($config['hint'], ['keys' => 1, 'touch' => 1]))
                + $this->attributes['hint'];
        if (isset($config['max_time']))    $this->attributes['max_time']    = max(10,  min(600,   (int)$config['max_time']));
        if (isset($config['max_events']))  $this->attributes['max_events']  = max(50,  min(20000, (int)$config['max_events']));
        if (isset($config['clock_slack'])) $this->attributes['clock_slack'] = max(0,   (int)$config['clock_slack']);
        if (isset($config['per_life']))    $this->attributes['per_life']    = (bool)$config['per_life'];
        if (isset($config['goal_ratio']))  $this->attributes['goal_ratio']  = max(0, min(100, (int)round((float)$config['goal_ratio'] * 100)));

        if (array_key_exists('fallback', $config))
            $this->attributes['fallback'] = self::resolveFallback($config['fallback']);
    }

    /** false/null — без запасного пути; массив дополняется значениями игры по умолчанию. */
    private static function resolveFallback($value): ?array {
        if (!is_array($value) || $value === []) return null;

        // У игры без запасного пути по умолчанию (defaultFallback() = []) пороги — базовые.
        $value += static::defaultFallback() + self::FALLBACK;

        return [
            'time'    => max(1, min(600, (int)$value['time'])),
            'actions' => max(1, min(1000, (int)$value['actions'])),
            'ratio'   => max(0, min(100, (int)round((float)$value['ratio'] * 100))),
            'score'   => max(0.0, min(1.0, (float)$value['score'])),
        ];
    }

    /**
     * Цель набрана, и не меньше $ratio процентов действий были осмысленными — набранное вслепую
     * не засчитывается. То же, что goalMet() в assets/engine/src/sim.js.
     */
    final protected static function goalMet(Simulation $sim, int $goal, int $ratio): bool {
        return $sim->peak >= $goal && $sim->good * 100 >= $ratio * $sim->acts;
    }

    /**
     * Запасной зачёт выполнен: сыграно не меньше time секунд, действий с намерением не меньше
     * actions, и доля осмысленных среди них не ниже ratio. То же, что fallbackMet() в
     * assets/engine/src/sim.js.
     */
    final protected static function fallbackMet(Simulation $sim, array $fallback, int $tps): bool {
        return $sim->tick >= (int)$fallback['time'] * $tps
            && $sim->acts >= (int)$fallback['actions']
            && $sim->good * 100 >= (int)$fallback['ratio'] * $sim->acts;
    }

    protected function requiredScore(): float {
        $base = parent::requiredScore();

        return $this->attributes['via'] === 'fallback' ? max($base, (float)$this->attributes['via_score']) : $base;
    }

    public function isAvailable(): bool {
        return is_file(static::bundle());
    }

    protected function forcedSignals(): array {
        return ['basic'];
    }

    /**
     * Дополнения выдачи от игры: `params` — обеим копиям игры, `secret` — только серверной
     * (браузер их не получает), `public` — в публичный конфиг рядом с игрой, `goal` — цель
     * этой выдачи вместо настроенной. $params — то, что передали в putCaptcha().
     */
    protected function prepare(array $params, int $seed): array {
        return [];
    }

    /** $params['seed'] — фиксированный seed (тесты, демо); иначе случайный. */
    protected function issue(array $params): array {
        $seed = isset($params['seed']) ? ((int)$params['seed'] & 0xFFFFFFFF) : 0;
        $seed = $seed ?: random_int(1, 0xFFFFFFFF);

        $fallback = $this->attributes['fallback'];
        $extra    = $this->prepare($params, $seed);
        [$w, $h]  = $this->attributes['size'];

        $game = [
            'seed'       => $seed,
            'tps'        => static::tps(),
            'max_ticks'  => static::tps() * (int)$this->attributes['max_time'],
            'size'       => [$w, $h],
            'params'     => (array)($extra['params'] ?? []) + static::layout($w, $h) + $this->params(),
        ];

        // Цель и пороги качества — только серверной копии: браузер о них не знает.
        $secret = $game + [
            'goal'       => (int)($extra['goal'] ?? $this->attributes['goal']),
            'goal_ratio' => (int)$this->attributes['goal_ratio'],
            'fallback'   => $fallback,
        ];
        $secret['params'] = (array)($extra['secret'] ?? []) + $game['params'];

        $public = (array)($extra['public'] ?? []) + $game + [
            'max_events' => (int)$this->attributes['max_events'],
            'collect'    => $fallback ? ['time' => $fallback['time'], 'actions' => $fallback['actions']] : null,
            'hint'       => $this->attributes['hint'],
            'big_button' => $this->attributes['big_button'],
        ];

        return [$secret, $public];
    }

    protected function render(array $public, string $id): string {
        [$w, $h] = $this->attributes['size'];

        return '<div class="st-captcha-game" style="width:'.(int)$w.'px;'
             . 'max-width:100%;aspect-ratio:'.(int)$w.'/'.(int)$h.'"></div>';
    }

    protected function verify(array $payload, array $state): bool {
        $this->attributes['via']       = '';
        $this->attributes['via_score'] = 0.0;

        $data   = @json_decode($this->answer($payload), true);
        $secret = (array)($state['secret'] ?? []);

        if (!is_array($data) || !isset($data['t'], $data['log']) || !is_int($data['t']) || !is_array($data['log']))
            return false;

        $ticks = $data['t'];
        $tps   = (int)($secret['tps'] ?? 0);
        $goal  = (int)($secret['goal'] ?? 0);

        if ($tps < 1 || $goal < 1 || $ticks < 0 || $ticks > (int)($secret['max_ticks'] ?? 0))
            return false;

        // Тики идут в реальном времени: партию нельзя прислать быстрее, чем её можно сыграть.
        $elapsed = time() - (int)($state['issued_at'] ?? 0) + (int)$this->attributes['clock_slack'];
        if ($ticks > $elapsed * $tps)
            return false;

        $log = self::normalizeLog($data['log'], $ticks, (int)$this->attributes['max_events']);
        if ($log === null)
            return false;

        $class = static::simulation();
        // Без остановки на цели: клиент засчитывает её только вместе с долей осмысленных действий
        // и может играть дальше, поэтому итог считается на присланном тике.
        $sim   = $class::replay((int)($secret['seed'] ?? 0), (array)($secret['params'] ?? []), $log, $ticks);

        if (static::goalMet($sim, $goal, (int)($secret['goal_ratio'] ?? 0))) {
            $this->attributes['via'] = 'goal';
            return true;
        }

        $fallback = $secret['fallback'] ?? null;

        if (is_array($fallback) && static::fallbackMet($sim, $fallback, $tps)) {
            $this->attributes['via']       = 'fallback';
            $this->attributes['via_score'] = (float)$fallback['score'];
            return true;
        }

        return false;
    }

    /** Лог как список [tick, code, value] целых чисел; null — если он не мог прийти от игры. */
    final protected static function normalizeLog(array $raw, int $ticks, int $max): ?array {
        if (count($raw) > $max) return null;

        $log     = [];
        $last    = 0;
        $perTick = 0;

        foreach (array_values($raw) as $event) {
            if (!is_array($event)) return null;

            $event = array_values($event);
            $size  = count($event);

            if ($size < 2 || $size > 3) return null;

            foreach ($event as $value)
                if (!is_int($value)) return null;

            [$tick, $code] = $event;
            $value = $event[2] ?? 0;

            if ($tick < $last || $tick > $ticks) return null;
            if ($code < 0 || $code > self::MAX_CODE) return null;
            if ($value < -self::MAX_VALUE || $value > self::MAX_VALUE) return null;

            $perTick = $tick === $last ? $perTick + 1 : 1;
            if ($perTick > self::MAX_PER_TICK) return null;

            $last  = $tick;
            $log[] = [$tick, $code, $value];
        }

        return $log;
    }

    protected function driverJs(): string {
        $file = static::bundle();

        if (!is_file($file))
            throw new \RuntimeException(static::class.': game bundle not found, run `npm run build` in '.__DIR__);

        // Бандл встраивается в <script> как есть: закрывающий тег внутри строк разорвал бы его.
        return str_ireplace('</script', '<\/script', (string)File::make($file)->getRaw());
    }

    protected function driverCss(): string {
        return '.st-captcha-game{display:block;position:relative;user-select:none;-webkit-user-select:none}';
    }

    /** dist/game.min.js в папке игры — той, где лежит класс драйвера. */
    final protected static function bundle(): string {
        return dirname((string)(new \ReflectionClass(static::class))->getFileName()).'/dist/game.min.js';
    }
}
