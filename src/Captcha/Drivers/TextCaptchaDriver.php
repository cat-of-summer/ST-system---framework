<?php

namespace ST_system\Captcha\Drivers;

use ST_system\Captcha\CaptchaDriver;
use ST_system\Captcha\Drivers\Image\GdAdapter;
use ST_system\Captcha\Drivers\Image\ImagickAdapter;
use ST_system\Storage\File;
use ST_system\Storage\Mimes\FontMime;
use ST_system\Storage\Mimes\ImageMime;

class TextCaptchaDriver extends CaptchaDriver {

    private const ADAPTERS = [
        'gd'      => GdAdapter::class,
        'imagick' => ImagickAdapter::class,
    ];

    protected static function getDefaultConfig(): array {
        return array_merge(static::baseConfig(), [
            'length'         => 4,
            'char_pool'      => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'width'          => 240,
            'height'         => 80,
            'font_size'      => 0,
            'distortion'     => 4,
            'noise'          => 6,
            'lines'          => 3,
            'contrast'       => 10,
            'fonts'          => '~/captcha_fonts',
            'font_types'     => ['ttf', 'otf'],
            'max_fonts'      => 50,
            'case_sensitive' => false,
            'image'          => 'auto',
        ]);
    }

    protected function __init(array $config): void {
        $this->attributes['length']         = max(3, min(12, (int)($config['length'] ?? 4)));
        $this->attributes['char_pool']      = (string)($config['char_pool'] ?? '') ?: 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $this->attributes['width']          = max(60, (int)($config['width'] ?? 240));
        $this->attributes['height']         = max(24, (int)($config['height'] ?? 80));
        $this->attributes['font_size']      = max(0, (int)($config['font_size'] ?? 0));
        $this->attributes['distortion']     = max(0, (int)($config['distortion'] ?? 4));
        $this->attributes['noise']          = max(0, (int)($config['noise'] ?? 6));
        $this->attributes['lines']          = max(0, (int)($config['lines'] ?? 3));
        $this->attributes['contrast']       = (int)($config['contrast'] ?? 10);
        $this->attributes['case_sensitive'] = (bool)($config['case_sensitive'] ?? false);
        $this->attributes['image']          = strtolower((string)($config['image'] ?? 'auto'));
        $this->attributes['font_types']     = array_values((array)($config['font_types'] ?? ['ttf', 'otf']));
        $this->attributes['max_fonts']      = max(0, (int)($config['max_fonts'] ?? 50));
        $this->attributes['fonts']          = $this->resolveFonts($config['fonts'] ?? '');
    }

    protected function __rebind(array $override): void {
        if (isset($override['font_types'])) $this->attributes['font_types'] = array_values((array)$override['font_types']);
        if (isset($override['max_fonts']))  $this->attributes['max_fonts']  = max(0, (int)$override['max_fonts']);

        if (isset($override['fonts']) || isset($override['font_types']) || isset($override['max_fonts']))
            $this->attributes['fonts'] = $this->resolveFonts($override['fonts'] ?? $this->attributes['fonts']);

        foreach (['length', 'width', 'height', 'font_size', 'distortion', 'noise', 'lines', 'contrast'] as $key)
            if (isset($override[$key])) $this->attributes[$key] = (int)$override[$key];

        if (isset($override['char_pool']))      $this->attributes['char_pool']      = (string)$override['char_pool'];
        if (isset($override['case_sensitive'])) $this->attributes['case_sensitive'] = (bool)$override['case_sensitive'];
        if (isset($override['image']))          $this->attributes['image']          = strtolower((string)$override['image']);
    }

    public function isAvailable(): bool {
        return $this->adapter() !== null && !empty($this->attributes['fonts']);
    }

    protected function providesAnswerField(): bool {
        return true;
    }

    protected function issue(array $params): array {
        $adapter = $this->adapter();

        if ($adapter === null)
            throw new \RuntimeException('TextCaptchaDriver: neither Imagick nor GD is available');

        $pool   = preg_split('//u', (string)$this->attributes['char_pool'], -1, PREG_SPLIT_NO_EMPTY);
        $size   = count($pool);
        $length = (int)$this->attributes['length'];

        if ($size < 2)
            throw new \InvalidArgumentException('TextCaptchaDriver: char_pool must contain at least 2 characters');

        $text = '';
        for ($i = 0; $i < $length; $i++)
            $text .= $pool[random_int(0, $size - 1)];

        $options = [
            'width'      => (int)$this->attributes['width'],
            'height'     => (int)$this->attributes['height'],
            'fonts'      => (array)$this->attributes['fonts'],
            'distortion' => (int)$this->attributes['distortion'],
            'noise'      => (int)$this->attributes['noise'],
            'lines'      => (int)$this->attributes['lines'],
            'contrast'   => (int)$this->attributes['contrast'],
        ];

        if ((int)$this->attributes['font_size'] > 0)
            $options['font_size'] = (int)$this->attributes['font_size'];

        $png = $adapter::render($text, $options);

        return [
            ['answer' => $this->normalize($text)],
            [
                'image'  => 'data:image/png;base64,'.base64_encode($png),
                'length' => $length,
            ],
        ];
    }

    protected function render(array $public, string $id): string {
        return '<img class="st-captcha-image" alt="captcha" src="'.self::esc($public['image'] ?? '').'">'
             . '<input type="text" class="st-captcha-input" name="'.self::esc($this->field('answer')).'" value=""'
             . ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"'
             . ' maxlength="'.(int)($public['length'] ?? 8).'" required>';
    }

    protected function verify(array $payload, array $state): bool {
        $expected = (string)($state['secret']['answer'] ?? '');
        $given    = $this->normalize($this->answer($payload));

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && STCaptcha.register('text', class extends STCaptcha.Driver {

    onMount() {
        var self  = this;
        var input = this.root.querySelector('.st-captcha-input');

        if (!input) return;

        input.addEventListener('input', function () {
            var filled = input.value.trim().length >= (self.cfg.length || 1);

            self.solvedFlag = filled;
            self.behavior.solved();
            self.setState(filled ? 'valid' : 'pending');
        });
    }
});
JS;
    }

    private function normalize(string $value): string {
        $value = trim($value);

        if ($this->attributes['case_sensitive']) return $value;

        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function adapter(): ?string {
        $requested = (string)$this->attributes['image'];

        if ($requested !== '' && $requested !== 'auto') {
            $class = self::ADAPTERS[$requested] ?? null;

            return ($class !== null && $class::isAvailable()) ? $class : null;
        }

        $order = ImageMime::getImageDriver() === 'gd' ? ['gd', 'imagick'] : ['imagick', 'gd'];

        foreach ($order as $name) {
            $class = self::ADAPTERS[$name];
            if ($class::isAvailable()) return $class;
        }

        return null;
    }

    private function resolveFonts($value): array {
        $sources = [];

        foreach (is_array($value) ? $value : [$value] as $source) {
            if ($source instanceof File) {
                $sources[] = $source;
                continue;
            }

            $source = trim((string)$source);
            if ($source === '') continue;

            $absolute = strpos($source, '/') === 0
                || strpos($source, '~') === 0
                || preg_match('#^[a-zA-Z]:[\\\\/]#', $source);

            $sources[] = $absolute ? $source : '~/'.ltrim($source, '/\\');
        }

        if (!$sources) return [];

        $extensions = [];
        foreach ((array)$this->attributes['font_types'] as $type) {
            $type = ltrim(trim((string)$type), '.');
            if ($type === '') continue;

            $extensions[] = strtolower($type);
            $extensions[] = strtoupper($type);
        }

        $files = File::find($sources, [
            'extension' => array_values(array_unique($extensions)),
            'recursive' => true,
            'max_files' => (int)$this->attributes['max_fonts'],
        ]);

        $fonts = [];

        foreach ($files as $file)
            if ($file instanceof File && $file->getServiceName() === FontMime::class)
                $fonts[$file->getPathname()] = $file->getPathname();

        return array_values($fonts);
    }
}
