<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class SvgMime extends Mime {

    public function toSprite(string $id, array $config = []): string {
        $attr_str = '';

        foreach ($config as $k => $v)
            $attr_str .= sprintf(' %s="%s"', $k, $v);
        
        return sprintf('<svg %s><use xlink:href="%s"></use></svg>', $attr_str, $this->file->getRelativePath().'#'.$id);
    }

    public function extractSprite(string $id, array $config = []): string {
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
                
                // 4. Собираем новый SVG
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
}