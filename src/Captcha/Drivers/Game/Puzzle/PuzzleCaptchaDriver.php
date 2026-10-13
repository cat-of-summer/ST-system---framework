<?php

namespace ST_system\Captcha\Drivers\Game\Puzzle;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;
use ST_system\Captcha\Drivers\Image\Adapters;
use ST_system\Captcha\Drivers\Image\Jigsaw;
use ST_system\Captcha\Drivers\Image\PuzzleAdapterInterface;
use ST_system\Storage\File;

/**
 * Пазл на столе: картинка разрезана на кусочки настоящей формы (Jigsaw — у каждого стыка свой
 * язычок), кусочки разбросаны рядом с полем — справа на горизонтальном холсте, снизу на
 * вертикальном, — их нужно перетащить на места. Перестановка секретная —
 * серверная копия игры получает её из закрытой части выдачи, браузер видит только спрайт с
 * вырезанными кусочками. Контуры уходят в браузер пикселями, не данными. Зачёт — все кусочки на
 * своих местах.
 */
class PuzzleCaptchaDriver extends GameCaptchaDriver {

    private const MAX_PIECES = 36;
    private const MAX_BOARD  = 400;
    private const GAP        = 6;

    /** Спрайт рисуется вдвое плотнее холста: на телефоне и в развёрнутом виде картинка не мылится. */
    public const DENSITY = 2;

    protected static function simulation(): string { return PuzzleSimulation::class; }
    protected static function tps(): int           { return 10; }
    protected static function defaultGoal(): int   { return 9; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Перетащите кусочки на поле мышью или выберите стрелками и поставьте пробелом',
            'touch' => 'Перетащите кусочки на поле',
        ];
    }

    /** Сборка дольше, чем партия в игре на реакцию. */
    protected static function defaultMaxTime(): int {
        return 60;
    }

    /** Запасного зачёта нет: собран пазл или нет — однозначно. */
    protected static function defaultFallback(): array {
        return [];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'images'     => '~/captcha_images',
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_images' => 100,
            'pieces'     => 9,
            'quality'    => 82,
            'image'      => 'auto',
            'goal_ratio' => 0,
        ]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->applyPuzzle($config);
        $this->attributes['images'] = $this->resolveImages($config['images'] ?? []);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applyPuzzle($override);

        if (isset($override['images']) || isset($override['extensions']) || isset($override['max_images']))
            $this->attributes['images'] = $this->resolveImages($override['images'] ?? $this->attributes['images']);
    }

    private function applyPuzzle(array $config): void {
        if (isset($config['pieces']))     $this->attributes['grid']       = self::grid((int)$config['pieces']);
        if (isset($config['quality']))    $this->attributes['quality']    = max(40, min(95, (int)$config['quality']));
        if (isset($config['image']))      $this->attributes['image']      = (string)$config['image'];
        if (isset($config['max_images'])) $this->attributes['max_images'] = max(1, (int)$config['max_images']);

        if (isset($config['extensions']))
            $this->attributes['extensions'] = array_values(array_filter(array_map(
                static fn($e): string => strtolower(ltrim(trim((string)$e), '.')),
                (array)$config['extensions']
            )));
    }

    /** Сетка под число кусочков: rows = ⌊√n⌋, cols = ⌈n / rows⌉; 9 → 3×3, 12 → 3×4. */
    public static function grid(int $pieces): array {
        $pieces = max(4, min(self::MAX_PIECES, $pieces));
        $rows   = max(2, (int)floor(sqrt($pieces)));

        return [$rows, (int)ceil($pieces / $rows)];
    }

    /**
     * Раскладка стола под холст w × h: поле и лоток рядом с ним (справа или снизу — где поле
     * выйдет крупнее). Поле — наибольшее, при котором все кусочки ложатся в лоток, не налезая
     * друг на друга клетками; язычки (bleed) остаются в пределах холста.
     *
     * @return array{0: int, 1: int, 2: int, 3: string} [ширина клетки, высота клетки,
     *         bleed в пикселях спрайта, 'right' | 'below']
     */
    public static function table(int $w, int $h, int $rows, int $cols): array {
        $zone = $h - self::HUD;
        $n    = $rows * $cols;
        $best = null;

        foreach (['right', 'below'] as $tray) {
            for ($side = min(self::MAX_BOARD, $tray === 'right' ? $zone : $w); $side >= 40; $side--) {
                $cw = intdiv($side, $cols);
                $ch = intdiv($side, $rows);
                $sb = Jigsaw::bleed($cw * self::DENSITY, $ch * self::DENSITY);
                $b  = $sb / self::DENSITY;

                if ($tray === 'right') {
                    $fits = $rows * $ch + 2 * $b <= $zone
                        && intdiv((int)floor($w - 4 * $b - $cols * $cw), $cw + self::GAP)
                         * intdiv((int)floor($zone - 2 * $b + self::GAP), $ch + self::GAP) >= $n;
                } else {
                    $fits = $cols * $cw + 2 * $b <= $w
                        && intdiv((int)floor($w - 2 * $b + self::GAP), $cw + self::GAP)
                         * intdiv((int)floor($zone - 4 * $b - $rows * $ch), $ch + self::GAP) >= $n;
                }

                if (!$fits) continue;
                if ($best === null || $cw * $ch > $best[0] * $best[1]) $best = [$cw, $ch, $sb, $tray];
                break;
            }
        }

        if ($best === null)
            throw new \InvalidArgumentException(static::class.": canvas {$w}×{$h} is too small for {$n} pieces");

        return $best;
    }

    public function isAvailable(): bool {
        return parent::isAvailable() && $this->adapter() !== null && !empty($this->attributes['images']);
    }

    /** Адаптер нарезки: `gd`, `imagick`, `auto` или имя класса с PuzzleAdapterInterface. */
    protected function adapter(): ?string {
        return Adapters::resolve((string)$this->attributes['image'], PuzzleAdapterInterface::class);
    }

    /** $params['order'] — фиксированная перестановка (тесты, демо); иначе случайная. */
    protected function prepare(array $params, int $seed): array {
        $adapter = $this->adapter();
        if ($adapter === null)
            throw new \RuntimeException(static::class.': neither Imagick nor GD is available');

        $images = (array)$this->attributes['images'];
        if (!$images)
            throw new \RuntimeException(static::class.': no images, set the "images" option');

        [$rows, $cols] = $this->attributes['grid'];

        [$w, $h] = $this->attributes['size'];
        [$cw, $ch, $bleed, $tray] = self::table($w, $h, $rows, $cols);

        $n     = $rows * $cols;
        $sw    = $cw * self::DENSITY;
        $sh    = $ch * self::DENSITY;
        $order = isset($params['order']) ? self::validOrder($params['order'], $n) : self::shuffle($n);

        // Контуры вырезаются в пикселях спрайта: в браузер форма уходит картинкой, а не данными.
        $sprite = $adapter::puzzle(self::local($images[random_int(0, count($images) - 1)]), [
            'width'    => $sw * $cols,
            'height'   => $sh * $rows,
            'rows'     => $rows,
            'cols'     => $cols,
            'bleed'    => $bleed,
            'order'    => $order,
            'outlines' => Jigsaw::outlines($rows, $cols, $sw, $sh, $bleed),
            'quality'  => (int)$this->attributes['quality'],
        ]);

        return [
            'goal'   => $n,
            'params' => ['rows' => $rows, 'cols' => $cols],
            'secret' => ['order' => $order],
            'public' => [
                'sprite' => 'data:'.self::mime($sprite).';base64,'.base64_encode($sprite),
                'board'   => [$cw * $cols, $ch * $rows],
                'bleed'   => $bleed,
                'density' => self::DENSITY,
                'tray'    => $tray,
            ],
        ];
    }

    private static function mime(string $bytes): string {
        if (strncmp($bytes, "\xFF\xD8", 2) === 0)                                  return 'image/jpeg';
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';

        return 'image/png';
    }

    /** Случайная перестановка: в ячейке спрайта k — кусочек order[k]. */
    private static function shuffle(int $n): array {
        $order = range(0, $n - 1);

        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }

        return $order;
    }

    private static function validOrder($order, int $n): array {
        $order = array_map('intval', array_values((array)$order));
        $check = $order;
        sort($check);

        if ($check !== range(0, $n - 1))
            throw new \InvalidArgumentException('PuzzleCaptchaDriver: order must be a permutation of 0..'.($n - 1));

        return $order;
    }

    /** Локальный путь картинки: URL скачивается (и кешируется) через File::fetch(). */
    private static function local(File $file): string {
        return $file->is_uri ? $file->fetch()->getPathname() : $file->getPathname();
    }

    /** File, путь к файлу или каталогу, URL — по одному или списком. */
    private function resolveImages($value): array {
        $images = [];
        $paths  = [];

        foreach (is_array($value) ? $value : [$value] as $source) {
            if ($source instanceof File) {
                $images[] = $source;
                continue;
            }

            $source = trim((string)$source);
            if ($source === '') continue;

            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $images[] = File::make($source);
                continue;
            }

            $absolute = strpos($source, '/') === 0
                || strpos($source, '~') === 0
                || preg_match('#^[a-zA-Z]:[\\\\/]#', $source);

            $paths[] = $absolute ? $source : '~/'.ltrim($source, '/\\');
        }

        if ($paths) {
            $extensions = [];
            foreach ((array)$this->attributes['extensions'] as $ext) {
                $extensions[] = strtolower($ext);
                $extensions[] = strtoupper($ext);
            }

            foreach (File::find($paths, [
                'extension' => array_values(array_unique($extensions)),
                'recursive' => true,
                'max_files' => (int)$this->attributes['max_images'],
            ]) as $file)
                if ($file instanceof File) $images[] = $file;
        }

        return array_slice($images, 0, (int)$this->attributes['max_images']);
    }
}
