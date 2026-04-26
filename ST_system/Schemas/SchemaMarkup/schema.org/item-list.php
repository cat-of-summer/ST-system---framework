<?php

/**
 * Схема микроразметки Schema.org — ItemList.
 *
 * Использование:
 *   require_once __DIR__ . '/item-list.php';
 *
 *   $markup = Schema::create('schema.item-list')->fill([
 *       'name'  => 'Основные направления пластической хирургии в пиона едикус',
 *       'items' => [
 *           [
 *               'position'  => 1,
 *               'item_type' => 'MedicalProcedure',
 *               'item_name' => 'Липофилинг лица',
 *               'item_url'  => 'https://epiona-medicus.ru/services/lipofiling-zon-litsa/',
 *           ],
 *           [
 *               'position'  => 2,
 *               'item_type' => 'MedicalProcedure',
 *               'item_name' => 'Пластика век (лефаропластика)',
 *               'item_url'  => 'https://epiona-medicus.ru/services/plastika-vek/',
 *           ],
 *           [
 *               'position'  => 3,
 *               'item_type' => 'MedicalProcedure',
 *               'item_name' => 'Ринопластика',
 *               'item_url'  => 'https://epiona-medicus.ru/services/plastika-nosa-rinoplastika/',
 *           ],
 *       ],
 *   ]);
 *   echo $markup->print();
 */

use ST_system\Schema;

// ─────────────────────────────────────────────────────────────────────────────

Schema::scope('schema', function (): void {

    Schema::entity('item-list', ['fields' => [
        'name'            => 'required|string',
        'description'     => 'sometimes|string',
        'url'             => 'sometimes|url',
        'number_of_items' => 'sometimes|int',
        'items'           => [Schema::arrayOf('@list-item'), 'sometimes'],
    ], 'print' => function (Schema $s): string {
        $items = $s->field('items') ?? [];

        $data = [
            '@context'      => 'https://schema.org',
            '@type'         => 'ItemList',
            'name'          => $s->field('name'),
            'numberOfItems' => $s->field('number_of_items') ?? count($items),
        ];

        if ($s->field('description') !== null) {
            $data['description'] = $s->field('description');
        }

        if ($s->field('url') !== null) {
            $data['url'] = $s->field('url');
        }

        if (!empty($items)) {
            $data['itemListElement'] = array_map(
                static fn ($item) => $item->toArray(),
                $items
            );
        }

        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';

    }])->scope(function (): void {

        // ── ListItem ──────────────────────────────────────────────────────────
        Schema::entity('list-item', ['fields' => [
            'position'  => 'required|int',
            'name'      => 'sometimes|string',
            'url'       => 'sometimes|url',
            'item_type' => 'sometimes|string',
            'item_name' => 'sometimes|string',
            'item_url'  => 'sometimes|url',
        ], 'toArray' => function (Schema $s): array {
            $result = [
                '@type'    => 'ListItem',
                'position' => $s->field('position'),
            ];

            if ($s->field('name') !== null) {
                $result['name'] = $s->field('name');
            }

            if ($s->field('url') !== null) {
                $result['url'] = $s->field('url');
            }

            if ($s->field('item_name') !== null || $s->field('item_url') !== null) {
                $item = ['@type' => $s->field('item_type') ?? 'Thing'];

                if ($s->field('item_name') !== null) {
                    $item['name'] = $s->field('item_name');
                }

                if ($s->field('item_url') !== null) {
                    $item['url'] = $s->field('item_url');
                }

                $result['item'] = $item;
            }

            return $result;
        }]);

    });

});