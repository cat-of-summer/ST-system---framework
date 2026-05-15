<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class HtmlMime extends Mime {

    private ?\DOMDocument $dom = null;
    private ?\DOMXPath    $xpath = null;

    public function get($data) {
        return $this->loadDom((string)$data);
    }

    public function getDom(): \DOMDocument {
        return $this->dom ?? $this->loadDom($this->file->getRaw());
    }

    public function getXPath(): \DOMXPath {
        return $this->xpath ??= new \DOMXPath($this->getDom());
    }

    public function extract(array $schema, array $context = []): array {
        return $this->applySchema($schema, $this->getDom(), $this->getXPath(), $context);
    }

    private function loadDom(string $html): \DOMDocument {
        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        ));
        libxml_clear_errors();

        $this->dom   = $dom;
        $this->xpath = null;

        return $dom;
    }

    private function applySchema(array $schema, \DOMNode $context, \DOMXPath $xpath, array $data): array {
        $result = [];

        foreach ($schema as $key => $definition) {
            if (is_string($key) && strncmp($key, '@', 1) === 0) continue;

            if (is_string($definition))
                $definition = ['@xpath' => $definition];

            $selector = $definition['@xpath'];

            $global = isset($selector[0]) && $selector[0] === '~';
            if ($global)
                $selector = substr($selector, 1);
            elseif (!($context instanceof \DOMDocument))
                $selector = '.' . ltrim($selector, '.');

            $nodeList = $xpath->query($selector, $global ? null : $context);
            $nodes    = $nodeList ? iterator_to_array($nodeList) : [];

            $extract = $definition['@extract'] ?? null;
            $asArray = $definition['@array']   ?? true;

            if ($extract === null) {
                $values = array_map(
                    fn(\DOMNode $n) => trim(str_replace(["\u{00A0}", "\n"], '', $n->nodeValue)),
                    $nodes
                );
                $result[$key] = $asArray ? $values : ($values[0] ?? null);
            } elseif (is_callable($extract)) {
                $result[$key] = $asArray
                    ? $extract($nodes, $data)
                    : $extract($nodes[0] ?? null, $data);
            } elseif (is_array($extract)) {
                if ($asArray) {
                    $items = [];
                    foreach ($nodes as $node)
                        $items[] = $this->applySchema($extract, $node, $xpath, $data);
                    $result[$key] = $items;
                } else {
                    $result[$key] = isset($nodes[0])
                        ? $this->applySchema($extract, $nodes[0], $xpath, $data)
                        : null;
                }
            }
        }

        return $result;
    }
}
