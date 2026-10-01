<?php

namespace ST_system\Storage\Mimes;

use ST_system\Main;
use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Minifiable;
use ST_system\Storage\Mimes\Traits\Combinable;

class SvgMime extends Mime {

    use Minifiable;
    use Combinable;

    public function bySprite(string $id, array $config = []): string {
        if (!$this->file->is_uri && !$this->file->exists())
            throw new \InvalidArgumentException("File not found: {$this->file->getPathname()}");

        return sprintf('<svg%s><use xlink:href="%s"></use></svg>',
            $config ? ' '.static::getAttrString($config) : '',
            htmlspecialchars($this->file->getRelativePath().'#'.$id, ENT_QUOTES)
        );
    }

    /**
     * Символ спрайта как самостоятельный <svg>: атрибуты <symbol> (кроме id) переносятся
     * на корень и перекрываются $config, внутренние id получают суффикс вставки, чтобы
     * повторная вставка одной иконки не плодила дубли id масок и clipPath.
     */
    public function extractSprite(string $id, array $config = []): string {
        $symbol = $this->spriteSymbols()[$id] ?? null;
        if ($symbol === null) return '';

        static $counter = 0; $counter++;

        $body = $symbol['body'];
        if ($symbol['ids']) {
            $own  = array_flip($symbol['ids']);
            $body = preg_replace_callback(
                '/((?<![\w:-])id\s*=\s*["\']|url\(\s*#|(?<![\w-])href\s*=\s*["\']#)([^"\')\s]+)/',
                fn($m) => $m[1].$m[2].(isset($own[$m[2]]) ? '_'.$counter : ''),
                $body
            );
        }

        $attrs = array_merge(['xmlns' => 'http://www.w3.org/2000/svg'], $symbol['attrs'], $config);

        return '<svg '.static::getAttrString($attrs).'>'.$body.'</svg>';
    }

    /**
     * Символы спрайта: id => ['attrs' => [...], 'body' => '...', 'ids' => [...]].
     * Генераторы спрайтов выносят градиенты, маски и clipPath из <symbol> в общий <defs>;
     * такие элементы, на которые ссылается символ, дописываются в его body отдельным <defs>.
     * Файл разбирается один раз за запрос (ключ — путь и mtime).
     */
    private function spriteSymbols(): array {
        static $cache = [];

        if (!$this->file->is_uri && !$this->file->exists())
            throw new \InvalidArgumentException("File not found: {$this->file->getPathname()}");

        $key = $this->file->getPathname().'|'.$this->file->mtime;
        if (isset($cache[$key])) return $cache[$key];

        $content = $this->file->getRaw();
        $raw = [];

        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();

            libxml_use_internal_errors(true);
            $dom->loadXML($content, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();

            $root = $dom->documentElement;
            if (!$root || $root->localName !== 'svg') return $cache[$key] = [];

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('svg', $root->namespaceURI ?: 'http://www.w3.org/2000/svg');

            foreach ($xpath->query('//svg:symbol[@id]') as $node) {
                $attrs = [];
                foreach ($node->attributes as $attr)
                    if ($attr->nodeName !== 'id') $attrs[$attr->nodeName] = $attr->nodeValue;

                $body = '';
                foreach ($node->childNodes as $child)
                    $body .= $dom->saveXML($child);

                $raw[$node->getAttribute('id')] = [$attrs, trim($body)];
            }

            $lookup = static function (string $ref) use ($xpath, $dom): ?string {
                if (strpbrk($ref, '"\'') !== false) return null;
                $el = $xpath->query('//*[@id="'.$ref.'"][not(ancestor-or-self::svg:symbol)]')->item(0);
                return $el ? $dom->saveXML($el) : null;
            };
        } else {
            preg_match_all('/<symbol\b([^>]*)>(.*?)<\/symbol>/is', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $attr_str, $body]) {
                preg_match_all('/([\w:-]+)\s*=\s*(["\'])(.*?)\2/s', $attr_str, $pairs, PREG_SET_ORDER);

                $attrs = [];
                foreach ($pairs as [, $k, , $v]) $attrs[$k] = html_entity_decode($v, ENT_QUOTES | ENT_XML1);

                $id = $attrs['id'] ?? '';
                if ($id === '') continue;
                unset($attrs['id']);

                $raw[$id] = [$attrs, trim($body)];
            }

            $outside = preg_replace('/<symbol\b.*?<\/symbol>/is', '', $content);
            $lookup = static function (string $ref) use ($outside): ?string {
                $q = preg_quote($ref, '/');
                return preg_match('/<([\w:-]+)\b[^>]*(?<![\w:-])id\s*=\s*["\']'.$q.'["\'][^>]*?(?:\/>|>.*?<\/\1>)/s', $outside, $m) ? $m[0] : null;
            };
        }

        $symbols = [];
        foreach ($raw as $id => [$attrs, $body]) {
            $defs  = '';
            $queue = [$body];
            $ids   = [];
            $seen  = [];

            while ($queue) {
                $xml = array_shift($queue);

                preg_match_all('/(?<![\w:-])id\s*=\s*["\']([^"\']+)["\']/', $xml, $m);
                foreach ($m[1] as $inner) $ids[$inner] = true;

                preg_match_all('/url\(\s*#([^)\s]+)\s*\)|(?<![\w-])href\s*=\s*["\']#([^"\']+)["\']/', $xml, $m, PREG_SET_ORDER);
                foreach ($m as $ref) {
                    $ref = ($ref[2] ?? '') !== '' ? $ref[2] : $ref[1];
                    if (isset($ids[$ref]) || isset($seen[$ref])) continue;
                    $seen[$ref] = true;

                    if (($dep = $lookup($ref)) === null) continue;
                    $defs   .= $dep;
                    $queue[] = $dep;
                }
            }

            $symbols[$id] = [
                'attrs' => $attrs,
                'body'  => $body.($defs !== '' ? '<defs>'.$defs.'</defs>' : ''),
                'ids'   => array_keys($ids),
            ];
        }

        return $cache[$key] = $symbols;
    }

    public function toImg(array $config = []): string {
        $clear = !empty($config['clear']);
        unset($config['clear']);

        $attrs = array_merge(
            ['alt' => $this->file->getBasename()],
            $clear ? [] : $this->getSourceAttributes(),
            $config,
            ['src' => $this->file->getRelativePath()]
        );

        return '<img '.static::getAttrString($attrs).' />';
    }

    public function toHTML(array $config = []): string {
        $clear = !empty($config['clear']);
        unset($config['clear'], $config['src']);

        $attrs = array_merge($clear ? [] : $this->getSourceAttributes(), $config);

        return '<svg '.static::getAttrString($attrs).'><use href="'.$this->file->getRelativePath().'"></use></svg>';
    }

    protected function getSourceAttributes(): array {
        if (!$this->file->is_uri && !$this->file->exists())
            return [];

        $content = $this->file->getRaw();
        $attrs = [];

        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadXML($content, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();

            $root = $dom->documentElement;

            if ($root && $root->nodeName === 'svg')
                foreach ($root->attributes as $attr)
                    $attrs[$attr->name] = $attr->value;
        } elseif (preg_match('/<svg\b([^>]*)>/i', $content, $matches)) {
            if (preg_match_all('/([\w:.-]+)\s*=\s*(["\'])(.*?)\2/', $matches[1], $am, PREG_SET_ORDER))
                foreach ($am as $a)
                    $attrs[$a[1]] = html_entity_decode($a[3], ENT_QUOTES);
        }

        return $attrs;
    }

    public function extract(array $config = []): string {
        if (!$this->file->is_uri && !$this->file->exists())
            throw new \InvalidArgumentException("File not found: {$this->file->getPathname()}");

        static $counter = 0; $counter++;

        $clear = !empty($config['clear']);
        unset($config['clear']);

        $content = $this->file->getRaw();

        if (class_exists('DOMDocument')) {

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadXML($content, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@id]') as $el) {
                $oldId = $el->getAttribute('id');
                $newId = $oldId.'_'.$counter;
                $el->setAttribute('id', $newId);

                foreach ($xpath->query('//*[@*]') as $refEl)
                    foreach ($refEl->attributes as $attr) {
                        $refEl->setAttribute(
                            $attr->name,
                            preg_replace('/url\(#' . preg_quote($oldId, '/') . '\)/', 'url(#' . $newId . ')', $attr->value)
                        );
                    }
            }

            if ($clear && $dom->documentElement)
                foreach (iterator_to_array($dom->documentElement->attributes) as $attr)
                    $dom->documentElement->removeAttribute($attr->name);

            if (!empty($config) && $dom->documentElement)
                foreach ($config as $k => $v)
                    $dom->documentElement->setAttribute($k, $v);

            return $dom->saveXML($dom->documentElement);
        } else {
            $content = preg_replace(
                '/\bid\s*=\s*(["\']?)([^"\'>\s]+)\1/',
                'id="$2'.'_'.$counter.'"',
                $content
            );

            $content = preg_replace(
                '/url\(#([^)]+)\)/',
                'url(#$1'.'_'.$counter.')',
                $content
            );

            if ($clear)
                $content = preg_replace('/<svg\b[^>]*>/i', '<svg>', $content);

            if (!empty($config) && preg_match('/<svg\b([^>]*)>/i', $content, $matches)) {
                $existingAttrs = $matches[1];

                foreach ($config as $k => $v) {
                    $attrPattern = '/\b' . preg_quote($k, '/') . '\s*=\s*["\'][^"\']*["\']/';
                    $attrValue   = sprintf('%s="%s"', $k, htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE));

                    if (preg_match($attrPattern, $existingAttrs))
                        $existingAttrs = preg_replace($attrPattern, $attrValue, $existingAttrs);
                    else
                        $existingAttrs .= ' ' . $attrValue;
                }

                $existingAttrs = ltrim($existingAttrs) ? ' ' . ltrim($existingAttrs) : '';

                $content = preg_replace('/<svg\b[^>]*>/i', '<svg' . $existingAttrs . '>', $content);
            }

            return $content;
        }
    }

    protected function __combine(array $files, array $config): string {
        static $data = [];

        $ns = $config['ns'] ?? 'http://www.w3.org/2000/svg';

        if (class_exists('DOMDocument')) {
            $out  = new \DOMDocument('1.0', 'UTF-8');
            $root = $out->createElementNS($ns, 'svg');
            $root->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
            $root->setAttribute('style', 'display:none');
            $out->appendChild($root);

            foreach ($files as $f) {
                $dom = new \DOMDocument();
                libxml_use_internal_errors(true);
                $dom->loadXML($f->getRaw(), LIBXML_NOWARNING | LIBXML_NOERROR);
                libxml_clear_errors();

                $svg = $dom->documentElement;
                if (!$svg || $svg->nodeName !== 'svg') continue;

                $symbols = [];
                foreach ($svg->childNodes as $child)
                    if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'symbol')
                        $symbols[] = $child;

                if ($symbols) {
                    foreach ($symbols as $sym) {
                        $id = $sym->getAttribute('id');
                        if ($id === '') continue;

                        $base = $id; $i = 2;
                        while (isset($data[$id])) { $id = $base.'_'.$i; $i++; }
                        $data[$id] = true;

                        $symbol = $out->createElementNS($ns, 'symbol');
                        $symbol->setAttribute('id', $id);
                        if ($sym->hasAttribute('viewBox'))
                            $symbol->setAttribute('viewBox', $sym->getAttribute('viewBox'));

                        foreach (iterator_to_array($sym->childNodes) as $child)
                            $symbol->appendChild($out->importNode($child, true));

                        $root->appendChild($symbol);
                    }
                    continue;
                }

                $id = $svg->getAttribute('id') ?: Main::snakeCase(pathinfo($f->getBasename(), PATHINFO_FILENAME));

                $base = $id; $i = 2;
                while (isset($data[$id])) { $id = $base.'_'.$i; $i++; }
                $data[$id] = true;

                $symbol = $out->createElementNS($ns, 'symbol');
                $symbol->setAttribute('id', $id);
                if ($svg->hasAttribute('viewBox'))
                    $symbol->setAttribute('viewBox', $svg->getAttribute('viewBox'));

                foreach (iterator_to_array($svg->childNodes) as $child)
                    $symbol->appendChild($out->importNode($child, true));

                $root->appendChild($symbol);
            }

            return $out->saveXML($root);
        }

        $body = '';
        foreach ($files as $f) {
            $raw = $f->getRaw();
            if (!preg_match('/<svg\b([^>]*)>(.*)<\/svg>/is', $raw, $m)) continue;

            $attrs = $m[1];
            $inner = $m[2];

            if (preg_match_all('/<symbol\b([^>]*)>(.*?)<\/symbol>/is', $inner, $sm, PREG_SET_ORDER)) {
                foreach ($sm as $s) {
                    $sAttrs = $s[1];
                    $sInner = $s[2];

                    if (!preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $sAttrs, $idm)) continue;
                    $id = $idm[1];

                    $base = $id; $i = 2;
                    while (isset($data[$id])) { $id = $base.'_'.$i; $i++; }
                    $data[$id] = true;

                    $vb = preg_match('/\bviewBox\s*=\s*["\']([^"\']+)["\']/i', $sAttrs, $vbm)
                        ? ' viewBox="'.htmlspecialchars($vbm[1], ENT_QUOTES).'"'
                        : '';

                    $body .= '<symbol id="'.htmlspecialchars($id, ENT_QUOTES).'"'.$vb.'>'.$sInner.'</symbol>';
                }
                continue;
            }

            $id = preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $idm)
                ? $idm[1]
                : Main::snakeCase(pathinfo($f->getBasename(), PATHINFO_FILENAME));

            $base = $id; $i = 2;
            while (isset($data[$id])) { $id = $base.'_'.$i; $i++; }
            $data[$id] = true;

            $vb = preg_match('/\bviewBox\s*=\s*["\']([^"\']+)["\']/i', $attrs, $vbm)
                ? ' viewBox="'.htmlspecialchars($vbm[1], ENT_QUOTES).'"'
                : '';

            $body .= '<symbol id="'.htmlspecialchars($id, ENT_QUOTES).'"'.$vb.'>'.$inner.'</symbol>';
        }

        return '<svg xmlns="'.$ns.'" xmlns:xlink="http://www.w3.org/1999/xlink" style="display:none">'.$body.'</svg>';
    }

    protected function __minify(string $content, array $config): string {
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);
        $content = preg_replace('/>\s+</', '><', $content);
        $content = preg_replace('/\s{2,}/', ' ', $content);
        return trim($content);
    }

    protected function __combineExtension(): string {
        return 'svg';
    }
}
