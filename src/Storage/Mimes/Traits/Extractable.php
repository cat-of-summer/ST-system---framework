<?php

namespace ST_system\Storage\Mimes\Traits;

use ST_system\Cache\Manager as Cache;
use ST_system\Storage\File;
use ST_system\Main;

/**
 * Контракт извлечения структурированных данных из DOM-ориентированного тела (HTML/XML).
 *
 * Реализующий mime задаёт только loadDom() (loadHTML vs loadXML); трейт даёт общий движок:
 * getDom()/getXPath() и extract($schema, $data) — рекурсивную выборку по xpath-схеме.
 * Работает и на in-memory Resource, и на File — тело берётся из $this->file->getRaw().
 *
 * Результат extract кешируется по идентификатору ресурса ($this->file->getId(): pathname у File,
 * ключ запроса у WebClient) + хешу схемы; инвалидация по mtime (File) либо по Main::hash тела
 * (in-memory — смена содержимого при том же id пересчитывает). Без id (аноним) — без кеша.
 */
trait Extractable {

    private ?\DOMDocument $dom   = null;
    private ?\DOMXPath    $xpath = null;

    /** Построение DOM из строки — специфично для типа (loadHTML / loadXML). */
    abstract protected function loadDom(string $content): \DOMDocument;

    public function getDom(): \DOMDocument {
        return $this->dom ??= $this->loadDom((string)$this->file->getRaw());
    }

    public function getXPath(): \DOMXPath {
        return $this->xpath ??= new \DOMXPath($this->getDom());
    }

    /** Выборка по xpath-схеме: строка-селектор или ['@xpath'=>, '@extract'=>, '@array'=>]. */
    public function extract(array $schema, array $data = []): array {
        $compute = fn() => $this->applySchema($schema, $this->getDom(), $this->getXPath(), $data);

        $id = $this->file->getId();
        if ($id === '') return $compute();

        return Cache::make($id, [
            'driver' => 'filesystem',
            'dir'    => File::config('cache.dir'),
            'file'   => Main::hash([$schema, $data]).'.json',
            'ttl'    => -1,
        ])->remember($compute, -1, '', $this->file->mtime ?: Main::hash($this->file->getRaw()));
    }

    protected function purgeDom(): void {
        $this->dom = $this->xpath = null;
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
                    ? array_map(fn($node) => $extract($node, $data), $nodes)
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
