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

        $attr_str = '';

        foreach ($config as $k => $v)
            $attr_str .= sprintf(' %s="%s"', $k, $v);
        
        return sprintf('<svg %s><use xlink:href="%s"></use></svg>', $attr_str, $this->file->getRelativePath().'#'.$id);
    }

    public function extractSprite(string $id, array $config = []): string {
        if (!$this->file->is_uri && !$this->file->exists())
            throw new \InvalidArgumentException("File not found: {$this->file->getPathname()}");

        $content = $this->file->getRaw();

        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();

            libxml_use_internal_errors(true);
            $dom->loadXML($content, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            
            $rootElement = $dom->documentElement;
            
            if (!$rootElement || $rootElement->nodeName !== 'svg') 
                return '';
            
            $xpath = new \DOMXPath($dom);
            
            $svgNamespace = $rootElement->namespaceURI;
            
            if (empty($svgNamespace))
                $svgNamespace = 'http://www.w3.org/2000/svg';
                        
            $xpath->registerNamespace('svg', $svgNamespace);
            
            $symbol = $xpath->query("//svg:symbol[@id='$id']")->item(0);

            if (!$symbol) return '';
            
            $newSvg = new \DOMDocument('1.0', 'UTF-8');
            $svgRoot = $newSvg->createElementNS('http://www.w3.org/2000/svg', 'svg');

            if ($symbol->hasAttribute('viewBox'))
                $svgRoot->setAttribute('viewBox', $symbol->getAttribute('viewBox'));
                            
            foreach ($config as $k => $v)
                $svgRoot->setAttribute($k, $v);
                            
            while ($symbol->firstChild) {
                $node = $symbol->removeChild($symbol->firstChild);
                $svgRoot->appendChild($newSvg->importNode($node, true));
            }

            $newSvg->appendChild($svgRoot);
            
            return $newSvg->saveXML($svgRoot);
        } else {
            $idQuoted = preg_quote($id, '/');

            $pattern = '/(<symbol\s+[^>]*?id\s*=\s*["\']' . $idQuoted . '["\'][^>]*>)(.*?)<\/symbol>/is';

            if (preg_match($pattern, $content, $matches)) {
                
                $openTag = $matches[1];
                $innerContent = trim($matches[2]);
                
                if (preg_match('/(viewBox\s*=\s*["\'][^"\']*["\'])/i', $openTag, $viewBoxMatch)) {
                    $viewBoxAttr = $viewBoxMatch[1];
                } else {
                    $viewBoxAttr = '';
                }

                $configAttrs = '';
                foreach ($config as $k => $v) {
                    $configAttrs .= sprintf(' %s="%s"', $k, htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE));
                }
                
                
                $newSvg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg"%s%s>%s</svg>',
                    ($viewBoxAttr ? ' ' . $viewBoxAttr : ''),
                    $configAttrs,
                    $innerContent
                );

                return $newSvg;
            }

            return '';
        }
    }

    public function toImg(array $config = []): string {
        $attrs = array_merge(
            ['alt' => $this->file->getBasename()],
            $config,
            ['src' => $this->file->getRelativePath()]
        );

        return '<img '.static::getAttrString($attrs).' />';
    }

    public function toHTML(array $config = []): string {
        unset($config['src']);

        return '<svg '.static::getAttrString($config).'><use href="'.$this->file->getRelativePath().'"></use></svg>';
    }

    public function extract(array $config = []): string {
        if (!$this->file->is_uri && !$this->file->exists())
            throw new \InvalidArgumentException("File not found: {$this->file->getPathname()}");

        static $counter = 0; $counter++;

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
