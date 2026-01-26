<?php

namespace ST_system\API\Drivers\Traits;

trait HasHTMLRules {

    private static $void_elements = [
        "area",
        "base",
        "br",
        "col",
        "embed",
        "hr",
        "img",
        "input",
        "link",
        "meta",
        "param",
        "source",
        "track",
        "wbr",
    ];

    abstract protected static function get_nodes_map(): array;

    final protected static function normalize_html($html) {
        if (!$html instanceof \DOMDocument) {
            if (!is_string($html))
                throw new \InvalidArgumentException('Передаваемый контент должен быть html-контентом или \DOMDocument объектом');
        
            $html = self::html_to_DOM($html);
        }

        $body = $html->getElementsByTagName('body')->item(0);
  
        return self::parse_nodes_recursive($body ? $body->childNodes : $html->childNodes);
    }

    final protected static function normalize_url(string $url) {
        $url = trim($url);

        if (strpos($url, '//') === false && strpos($url, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $url = "{$protocol}://{$host}".str_replace('//', '/', '/'.$url);
        }

        return $url;
    }

    final protected static function html_to_node_array(string $html) {
        $document = self::html_to_DOM($html);
        $body = $document->getElementsByTagName('body')->item(0);

        $nodes =  $body ? $body->childNodes : $document->childNodes;

        $result = [];
        foreach ($nodes as $node)
            $result[] = $node;
        
        return $result;
    }

    private static function html_to_DOM(string $html) {
        libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $document;
    }

    private static function parse_nodes_recursive(\DOMNodeList $nodes) {
        $node_map = static::get_nodes_map();

        $html = '';
        foreach ($nodes as $node) {
            $tag = mb_strtolower($node->nodeName);
            $attributes = $node->hasAttributes() 
                ? array_column(iterator_to_array($node->attributes ?? []), 'nodeValue', 'nodeName')
                : [];

            if ($node->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/\s+/u', ' ', $node->textContent);
                if (trim($text) !== '')
                    $html .= $text;
                
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE)
                continue;
            
            if (!array_key_exists($tag, $node_map)) {
                $html .= self::parse_nodes_recursive($node->childNodes);

                continue;
            }

            $mapped = $node_map[$tag];

            if ($mapped === false) continue;

            if (is_callable($mapped)) {
                $html .= call_user_func($mapped, self::parse_nodes_recursive($node->childNodes), $attributes);
                
                continue;
            }

            if (is_string($mapped)) {
                $html .= $mapped;
                
                continue;
            }

            $attrs = [];

            if ($node->hasAttribute('href'))
                $attrs['href'] = self::normalize_url($node->getAttribute('href'));

            if ($node->hasAttribute('src'))
                $attrs['src'] = self::normalize_url($node->getAttribute('src'));

            if ($node->hasAttribute('alt'))
                $attrs['alt'] = $node->getAttribute('alt');

            if ($node->hasAttribute('srcset')) {
                $fixed = [];
                foreach (explode(',', $node->getAttribute('srcset')) as $part) {
                    [$u, $d] = array_pad(preg_split('/\s+/', trim($part)), 2, '');
                    $fixed[] = trim(self::normalize_url($u).' '.$d);
                }
                if ($fixed)
                    $attrs['srcset'] = implode(', ', $fixed);
            }

            $attr_str = '';
            array_walk($attrs, function($v, $k) use (&$attr_str) {
                $attr_str .= ' '.$k.'="'.htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"';
            });

            $html .= in_array($tag, self::$void_elements)
                ? "<{$tag}{$attr_str} />"
                : "<{$tag}{$attr_str}>".self::parse_nodes_recursive($node->childNodes)."</{$tag}>";

        }

        return $html;
    }
}
