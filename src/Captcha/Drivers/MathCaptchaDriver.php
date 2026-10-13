<?php

namespace ST_system\Captcha\Drivers;

/**
 * Пример на картинке: арифметика (`14 + 7 = ?`) или лёгкое уравнение (`3x + 4 = 19`), в поле —
 * целый ответ. Рисование, шрифты, искажения и попытки — от TextCaptchaDriver.
 */
class MathCaptchaDriver extends TextCaptchaDriver {

    private const LEVELS = ['arithmetic', 'equation', 'mixed'];

    /** Тип последнего задания (arithmetic | equation) — для плейсхолдера поля. */
    private string $kind = 'arithmetic';

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'level' => 'arithmetic',
            'times' => '×',
            'width' => 280,
        ]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['level'] = self::level($config['level'] ?? 'arithmetic');
        $this->attributes['times'] = (string)($config['times'] ?? '') ?: '×';
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        if (isset($override['level'])) $this->attributes['level'] = self::level($override['level']);
        if (isset($override['times'])) $this->attributes['times'] = (string)$override['times'] ?: '×';
    }

    private static function level($value): string {
        $value = strtolower(trim((string)$value));

        if (!in_array($value, self::LEVELS, true))
            throw new \InvalidArgumentException("MathCaptchaDriver: unknown level '{$value}', expected ".implode(' | ', self::LEVELS));

        return $value;
    }

    protected function challenge(): array {
        $level = $this->attributes['level'];

        if ($level === 'mixed')
            $level = random_int(0, 1) ? 'equation' : 'arithmetic';

        $this->kind = $level;

        [$text, $answer] = $level === 'equation' ? self::equation() : $this->arithmetic();

        // Рендер ставит символы с равным шагом — пробел занял бы целую клетку и ужал бы цифры.
        return [str_replace(' ', '', $text), (string)$answer];
    }

    /** a + b, a - b (не меньше нуля), a × b. */
    private function arithmetic(): array {
        switch (random_int(0, 2)) {
            case 0:
                $a = random_int(1, 20);
                $b = random_int(1, 20);
                return ["{$a} + {$b} = ?", $a + $b];

            case 1:
                $a = random_int(5, 20);
                $b = random_int(1, $a);
                return ["{$a} - {$b} = ?", $a - $b];

            default:
                $a = random_int(2, 9);
                $b = random_int(2, 9);
                return ["{$a} {$this->attributes['times']} {$b} = ?", $a * $b];
        }
    }

    /** x + a = c, x - a = c, ax = c, ax + b = c; x — целое 1..12. */
    private static function equation(): array {
        $x = random_int(1, 12);

        switch (random_int(0, 3)) {
            case 0:
                $a = random_int(1, 15);
                return ["x + {$a} = ".($x + $a), $x];

            case 1:
                $a = random_int(1, $x);
                return ["x - {$a} = ".($x - $a), $x];

            case 2:
                $a = random_int(2, 9);
                return ["{$a}x = ".($a * $x), $x];

            default:
                $a = random_int(2, 5);
                $b = random_int(1, 12);
                return ["{$a}x + {$b} = ".($a * $x + $b), $x];
        }
    }

    /** Длину ответа не раскрываем: поле считается заполненным с первой цифры. */
    protected function answerLength(string $text): int {
        return 1;
    }

    protected function inputAttributes(array $public): string {
        $equation = ($public['kind'] ?? '') === 'equation';

        return ' inputmode="numeric" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"'
             . ' maxlength="4" required placeholder="'.($equation ? 'x =' : '?').'"';
    }

    protected function issue(array $params): array {
        [$secret, $public] = parent::issue($params);

        // Тип задания — для плейсхолдера; текст задания в публичный конфиг не идёт.
        $public['kind'] = $this->kind;

        return [$secret, $public];
    }

    /** Целое число: пробелы отбрасываются, ведущий плюс и нули не мешают. */
    protected function normalize(string $value): string {
        $value = preg_replace('/\s+/u', '', $value);
        $value = str_replace(['−', '–'], '-', (string)$value);

        if (!preg_match('/^([+-]?)0*(\d{1,6})$/', $value, $m)) return '';

        return ($m[1] === '-' && $m[2] !== '0' ? '-' : '').$m[2];
    }
}
