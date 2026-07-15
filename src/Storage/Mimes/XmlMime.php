<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Main;
use ST_system\Storage\Mimes\Traits\Extractable;

class XmlMime extends Mime {

    use Extractable;

    public function get($data) {
        return (string)$data;
    }

    protected function loadDom(string $xml): \DOMDocument {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;

        if (!$doc->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET) || $doc->documentElement === null) {
            $errors = array_map(fn(\LibXMLError $e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \Exception("Ошибка при декодировании XML: '".(implode('; ', $errors) ?: 'некорректный XML')."'");
        }

        libxml_clear_errors();

        return $doc;
    }

    public function toArray(): array {
        $root = $this->getDom()->documentElement;
        return [$root->nodeName => self::domNodeToArray($root)];
    }

    private static function domNodeToArray(\DOMNode $node) {
        $result = [];

        if ($node->hasAttributes())
            foreach ($node->attributes as $attr)
                $result['@attributes'][$attr->nodeName] = $attr->nodeValue;

        $children = [];
        $text     = '';

        foreach ($node->childNodes as $child) {
            switch ($child->nodeType) {
                case XML_ELEMENT_NODE:
                    $children[] = $child;
                    break;
                case XML_TEXT_NODE:
                case XML_CDATA_SECTION_NODE:
                    $text .= $child->nodeValue;
                    break;
            }
        }

        foreach ($children as $child) {
            $name  = $child->nodeName;
            $value = self::domNodeToArray($child);

            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
                continue;
            }

            if (!is_array($result[$name]) || !Main::arrayIsList($result[$name]))
                $result[$name] = [$result[$name]];

            $result[$name][] = $value;
        }

        $text = trim($text);

        if ($text === '')
            return $result === [] ? '' : $result;

        if ($result === [])
            return $text;

        $result['@text'] = $text;
        return $result;
    }
}
