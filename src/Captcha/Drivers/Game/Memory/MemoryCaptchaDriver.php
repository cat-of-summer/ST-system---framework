<?php

namespace ST_system\Captcha\Drivers\Game\Memory;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;
use ST_system\Captcha\Drivers\Game\ImageSources;
use ST_system\Captcha\Drivers\Image\Adapters;
use ST_system\Captcha\Drivers\Image\PuzzleAdapterInterface;

/**
 * Memory: все карточки на несколько секунд открываются, затем закрываются — пары нужно найти по
 * памяти, ошибки отнимают жизни. Сложность — ключ `level` (easy | normal | hard): число пар, время
 * показа и жизни. Цель — найти все пары, ключ `goal` её не меняет.
 *
 * Лица карточек — ключ `faces`: `emoji` — из списка `emoji`, `images` — картинки из `images`, как у
 * пазла, каждая кадрируется адаптером под карточку. Какие лица попадут в выдачу, сервер выбирает
 * сам; расклад — от seed.
 */
class MemoryCaptchaDriver extends GameCaptchaDriver {

    use ImageSources;

    /** Уровни: [пар, секунд показа, жизней]. */
    public const LEVELS = [
        'easy'   => [6, 6, 5],
        'normal' => [8, 5, 4],
        'hard'   => [10, 4, 3],
    ];

    /** Лицо карточки из картинки, пиксели: пропорции игральной карты 63 × 88, вдвое плотнее карточки на холсте. */
    public const CARD = [126, 176];

    public const EMOJI = [
        '🍎', '🍌', '🍇', '🍉', '🍓', '🍒', '🍍', '🥝', '🥕', '🌽', '🍄', '🌵', '🌻', '🌈', '⭐',
        '🔥', '⚡', '🐶', '🐱', '🦊', '🐼', '🐸', '🐙', '🦋', '🐢', '🚀', '⚽', '🎈', '🎁', '🎲',
    ];

    protected static function simulation(): string { return MemorySimulation::class; }
    protected static function tps(): int           { return 10; }
    protected static function defaultGoal(): int   { return self::LEVELS['normal'][0]; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Запомните карточки, затем открывайте пары: клик или стрелки и пробел',
            'touch' => 'Запомните карточки, затем открывайте пары касанием',
        ];
    }

    /** Показ, поиск пар и, бывает, второй расклад после ошибок. */
    protected static function defaultMaxTime(): int {
        return 90;
    }

    /** Порога сбора данных нет: ответ — когда найдены все пары или вышло время. */
    protected static function defaultCollect(): array {
        return [];
    }

    /** Ошибки прошлых жизней остаются в счёте: цель засчитывается и при половине точных пар. */
    protected static function defaultGoalRatio(): float {
        return 0.5;
    }

    /** Наугад пара совпадает редко (1 / 15 при восьми); по памяти — чаще, чем через раз. */
    protected static function defaultQuality(): array {
        return [0.25, 0.65];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'level'      => 'normal',
            'faces'      => 'emoji',
            'emoji'      => self::EMOJI,
            'images'     => '~/captcha_images',
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_images' => 100,
            'image'      => 'auto',
            'quality'    => 82,
        ]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['level']  = 'normal';
        $this->attributes['faces']  = 'emoji';
        $this->attributes['emoji']  = self::EMOJI;
        $this->attributes['images'] = [];
        $this->applyMemory($config);

        if ($this->attributes['faces'] === 'images')
            $this->attributes['images'] = $this->resolveImages($config['images'] ?? []);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applyMemory($override);

        if ($this->attributes['faces'] === 'images'
            && (isset($override['images']) || isset($override['faces']) || isset($override['extensions']) || isset($override['max_images'])))
            $this->attributes['images'] = $this->resolveImages($override['images'] ?? $this->attributes['images']);
    }

    private function applyMemory(array $config): void {
        if (isset($config['level'])) {
            $level = strtolower(trim((string)$config['level']));
            if (!isset(self::LEVELS[$level]))
                throw new \InvalidArgumentException("MemoryCaptchaDriver: unknown level '{$level}', expected ".implode(' | ', array_keys(self::LEVELS)));
            $this->attributes['level'] = $level;
        }

        if (isset($config['faces'])) {
            $faces = strtolower(trim((string)$config['faces']));
            if ($faces !== 'emoji' && $faces !== 'images')
                throw new \InvalidArgumentException("MemoryCaptchaDriver: unknown faces '{$faces}', expected emoji | images");
            $this->attributes['faces'] = $faces;
        }

        if (isset($config['emoji']))
            $this->attributes['emoji'] = array_values(array_unique(array_filter(array_map(
                static fn($e): string => trim((string)$e),
                (array)$config['emoji']
            ), 'strlen')));

        if (isset($config['image']))      $this->attributes['image']      = (string)$config['image'];
        if (isset($config['quality']))    $this->attributes['quality']    = max(40, min(95, (int)$config['quality']));
        if (isset($config['max_images'])) $this->attributes['max_images'] = max(1, (int)$config['max_images']);

        if (isset($config['extensions']))
            $this->attributes['extensions'] = array_values(array_filter(array_map(
                static fn($e): string => strtolower(ltrim(trim((string)$e), '.')),
                (array)$config['extensions']
            )));
    }

    private function pairs(): int {
        return self::LEVELS[$this->attributes['level']][0];
    }

    /** Лиц хватает на все пары уровня; для картинок нужен ещё адаптер. */
    public function isAvailable(): bool {
        if (!parent::isAvailable()) return false;

        if ($this->attributes['faces'] === 'emoji')
            return count((array)$this->attributes['emoji']) >= $this->pairs();

        return $this->adapter() !== null && count((array)$this->attributes['images']) >= $this->pairs();
    }

    /** Адаптер кадрирования: `gd`, `imagick`, `auto` или имя класса с PuzzleAdapterInterface. */
    protected function adapter(): ?string {
        return Adapters::resolve((string)$this->attributes['image'], PuzzleAdapterInterface::class);
    }

    protected function params(): array {
        [$pairs, $seconds, $lives] = self::LEVELS[$this->attributes['level']];

        return parent::params() + [
            'pairs' => $pairs,
            'lives' => $lives,
            'show'  => $seconds * static::tps(),
        ];
    }

    protected function prepare(array $params, int $seed): array {
        $pairs = $this->pairs();

        return [
            'goal'   => $pairs,
            'public' => ['faces' => $this->attributes['faces'], 'cards' => $this->attributes['faces'] === 'images'
                ? $this->imageCards($pairs)
                : self::pick((array)$this->attributes['emoji'], $pairs)],
        ];
    }

    /** $count случайных разных элементов списка. */
    private static function pick(array $list, int $count): array {
        if (count($list) < $count)
            throw new \RuntimeException(static::class.": need {$count} faces, got ".count($list));

        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        return array_slice(array_values($list), 0, $count);
    }

    /** Лица из картинок: каждая кадрирована под карточку, в браузер — data URI. */
    private function imageCards(int $pairs): array {
        $adapter = $this->adapter();
        if ($adapter === null)
            throw new \RuntimeException(static::class.': neither Imagick nor GD is available');

        $cards = [];
        foreach (self::pick((array)$this->attributes['images'], $pairs) as $file)
            $cards[] = self::dataUri($adapter::puzzle(self::local($file), [
                'width'   => self::CARD[0],
                'height'  => self::CARD[1],
                'rows'    => 1,
                'cols'    => 1,
                'bleed'   => 0,
                'order'   => [0],
                'quality' => (int)$this->attributes['quality'],
            ]));

        return $cards;
    }
}
