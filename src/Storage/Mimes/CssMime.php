<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Minifiable;
use ST_system\Storage\Mimes\Traits\Combinable;

class CssMime extends Mime {

    use Minifiable;
    use Combinable;

    public function toHTML(array $config = []): string {
        $type = $config['type'] ?? 'text/css';
        $media = ($config['media'] ?? null) ? "media='{$config['media']}'" : '';

        return "<link rel='stylesheet' href='{$this->file->getRelativePath()}' type='{$type}' $media>";
    }

    public static function __minify(string $content, array $config): string {
        // Строки и url(...) без кавычек вынимаются целиком, комментарии выбрасываются —
        // одним проходом, чтобы кавычка внутри комментария и наоборот не сбили разбор.
        $kept    = [];
        $content = preg_replace_callback(
            '~/\*[\s\S]*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|url\(\s*[^)\'"\s]*\s*\)~',
            static function (array $m) use (&$kept): string {
                if (strncmp($m[0], '/*', 2) === 0) return '';
                $kept["\0".count($kept)."\0"] = strncmp($m[0], 'url(', 4) === 0
                    ? 'url('.trim(substr($m[0], 4, -1)).')'
                    : $m[0];
                return array_key_last($kept);
            },
            $content
        );

        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/ ?([{};,>~]) ?/', '$1', $content);
        $content = str_replace(['( ', ' )'], ['(', ')'], $content);

        // Пробел вокруг `+` и перед `:` значим не везде: в селекторе `+` — комбинатор,
        // а `.a :hover` и `.a:hover` — разные селекторы; в объявлении `+` живёт в calc(),
        // где пробелы обязательны, а `:` отделяет свойство от значения.
        $content = preg_replace_callback('/([^{};]*)([{};]|$)/', static function (array $m): string {
            if ($m[2] === '{')
                $part = preg_replace(['/ ?\+ ?/', '/: /'], ['+', ':'], $m[1]);
            else
                $part = preg_replace('/ ?: ?/', ':', $m[1], 1);

            return $part.$m[2];
        }, $content);

        $content = str_replace(';}', '}', $content);

        return trim(strtr($content, $kept));
    }

    protected function __combine(array $files, array $config): string {
        return implode("\n", array_map(fn($f) => $f->getRaw(), $files));
    }

    protected function __combineExtension(): string {
        return 'css';
    }
}
