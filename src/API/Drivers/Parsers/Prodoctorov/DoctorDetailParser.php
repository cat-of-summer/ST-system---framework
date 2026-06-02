<?php

namespace ST_system\API\Drivers\Parsers\Prodoctorov;

use ST_system\API\Drivers\Parsers\DefaultParser;

final class DoctorDetailParser extends DefaultParser {

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'delay' => 5000
        ]);
    }

    protected function __init(): void {
        parent::__init();

        $isSingle = false;

        $this->on('before_fetch', function($input) use (&$isSingle) {
            $isSingle = !is_array($input)
                || (isset($input['vrach_id']) && !is_array($input['vrach_id']));
        });

        $this->on('after_fetch_one', function(&$result) {
            $result = $result['data'];
        });

        $this->on('after_fetch', function(&$results) use (&$isSingle) {
            if ($isSingle) {
                $results = $results[0] ?? [];
            }
        });
    }

    protected function getSchema(): array {
        return [
            'name' => [
                '@xpath'   => '//span[@itemprop="name" and contains(@class,"d-block")]',
                '@array'   => false,
                '@extract' => fn($n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->nodeValue)) : null,
            ],
            'avatar' => [
                '@xpath'   => '//img[@itemprop="image" and contains(@class,"b-doctor-intro__avatar")]/@src',
                '@array'   => false,
                '@extract' => function($n, $data) {
                    if (!$n) return null;
                    $src = $n->nodeValue;
                    if ($src && strpos($src, 'http') !== 0) {
                        $p   = parse_url($data['url']);
                        $src = $p['scheme'] . '://' . $p['host'] . $src;
                    }
                    return $src;
                },
            ],
            'specialties' => [
                '@xpath'   => '//div[contains(@class,"b-doctor-intro__specs")]//a[contains(@class,"b-doctor-intro__spec")]',
                '@extract' => fn($n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->nodeValue)) : null,
            ],
            'experience' => [
                '@xpath'   => '//div[contains(@class,"b-doctor-intro__documents-plate")]//div[contains(@class,"text-subtitle-1")]',
                '@array'   => false,
                '@extract' => function($n) {
                    if (!$n || !preg_match('/\d+/', $n->nodeValue, $m)) return null;
                    return (int) $m[0];
                },
            ],
            'languages' => [
                '@xpath'   => '//*[@id="doctor-languages"]//span[contains(@class,"text-body-1") and contains(@class,"text--text")]',
                '@extract' => fn($n) => $n ? trim($n->nodeValue) : null,
            ],
            'treatment_profiles' => [
                '@xpath'   => '//ul[contains(@class,"b-doctor-details__list_column")]//li[contains(@class,"b-doctor-details__list-item_column")]',
                '@extract' => [
                    'percent' => [
                        '@xpath'   => '//div[contains(@class,"text-body-2") and contains(@class,"text-secondary--text")]',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? (int) preg_replace('/\D+/', '', $n->nodeValue) : null,
                    ],
                    'text' => [
                        '@xpath'   => '//a[contains(@class,"text-body-1") and contains(@class,"text--text")]',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? trim($n->nodeValue) : null,
                    ],
                ],
            ],
            'job' => [
                '@xpath'   => '//*[@id="job"]//div[contains(@class,"b-doctor-details__item-description")]',
                '@extract' => function($n) {
                    if (!$n) return null;
                    $xpath = new \DOMXPath($n->ownerDocument);

                    $titleNodes = $xpath->query(
                        'preceding-sibling::div[1]//div[contains(@class,"b-doctor-details__data-title")]',
                        $n
                    );
                    $name = $titleNodes->length > 0
                        ? trim($titleNodes->item(0)->nodeValue)
                        : null;

                    $periodNodes = $xpath->query(
                        './/div[contains(@class,"text-info--text") and contains(@class,"mb-1")]',
                        $n
                    );
                    $period = $periodNodes->length > 0
                        ? trim($periodNodes->item(0)->nodeValue)
                        : null;

                    return compact('name', 'period');
                },
            ],
            'education' => [
                '@xpath'   => '//*[@id="educations"]//div[contains(@class,"b-doctor-details__item-description")]',
                '@extract' => function($n) {
                    if (!$n) return null;
                    $xpath = new \DOMXPath($n->ownerDocument);

                    $titleNodes = $xpath->query(
                        'preceding-sibling::div[1]//div[contains(@class,"b-doctor-details__data-title")]',
                        $n
                    );
                    $institution = $titleNodes->length > 0
                        ? trim(preg_replace('/\s+/u', ' ', $titleNodes->item(0)->nodeValue))
                        : null;

                    $yearNodes = $xpath->query(
                        './/div[contains(@class,"text-info--text") and contains(@class,"mb-1")]',
                        $n
                    );
                    $year = $yearNodes->length > 0
                        ? trim($yearNodes->item(0)->nodeValue)
                        : null;

                    $specialtyNodes = $xpath->query(
                        './/div[contains(@class,"text--text") and contains(@class,"mb-1") and not(contains(@class,"text-info--text"))]',
                        $n
                    );
                    $specialty = $specialtyNodes->length > 0
                        ? trim(preg_replace('/\s+/u', ' ', $specialtyNodes->item(0)->nodeValue))
                        : null;

                    $typeNodes = $xpath->query(
                        './/div[contains(@class,"text-body-2") and contains(@class,"text-info--text")]',
                        $n
                    );
                    $type = $typeNodes->length > 0
                        ? trim($typeNodes->item(0)->nodeValue)
                        : null;

                    return compact('institution', 'year', 'specialty', 'type');
                },
            ],
            'courses' => [
                '@xpath'   => '//*[@id="courses"]//div[contains(@class,"text-body-1") and contains(@class,"mb-4")]',
                '@extract' => [
                    'year' => [
                        '@xpath'   => '//div[contains(@class,"b-doctor-details__data-title")]',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? trim($n->nodeValue) : null,
                    ],
                    'text' => [
                        '@xpath'   => '//div[contains(@class,"text--text") and contains(@class,"pl-7")]',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? trim($n->nodeValue) : null,
                    ],
                ],
            ],
            'associations' => [
                '@xpath'   => '//*[@id="associations"]//div[contains(@class,"b-doctor-details__data-title") and contains(@class,"text--text") and not(contains(@class,"text-info--text"))]',
                '@extract' => fn($n) => $n ? trim($n->nodeValue) : null,
            ],
            'documents' => [
                '@xpath'   => '//*[@id="documents"]//a[contains(@class,"b-popup-gallery__preview")]',
                '@extract' => function($n, $data) {
                    if (!$n) return null;
                    $href = $n->getAttribute('href');
                    if ($href && strpos($href, 'http') !== 0) {
                        $p    = parse_url($data['url']);
                        $href = $p['scheme'] . '://' . $p['host'] . $href;
                    }
                    $title = $n->getAttribute('title') ?: null;
                    return ['url' => $href ?: null, 'title' => $title];
                },
            ],
        ];
    }

    protected function getTemplate(): string { return 'https://prodoctorov.ru/kaliningrad/vrach/{vrach_id}/'; }
}
