<?php

namespace ST_system\API\Drivers\Parsers\Prodoctorov;

use ST_system\API\Drivers\Parsers\DefaultParser;

final class DoctorsReviewsParser extends DefaultParser {

    protected function getSchema(): array {
        return [
            'name' => [
                '@xpath'   => '//span[@itemprop="name" and contains(@class,"d-block")]',
                '@array'   => false,
            ],
            'avatar' => [
                '@xpath'   => '//img[@itemprop="image" and contains(@class,"b-doctor-intro__avatar")]/@src',
                '@array'   => false,
                '@extract' => fn($n) => $n?->nodeValue,
            ],
            'price' => [
                '@xpath'   => '//div[contains(@class,"b-doctor-intro__price")]',
                '@array'   => false,
                '@extract' => fn($n) => $n ? (int) preg_replace('/\D+/', '', $n->nodeValue) : null,
            ],
            'description' => [
                '@xpath'   => '//div[contains(@class,"b-doctor-intro__right-side")]//p[contains(@class,"text-body-2") and contains(@class,"text-secondary--text")]',
                '@array'   => false,
            ],
            'updated' => [
                '@xpath'   => '//div[contains(@class,"text-body-2") and contains(@class,"mt-2") and contains(.,"Обновлено")]',
                '@array'   => false,
                '@extract' => fn($n) => $n
                    ? preg_replace('~^.*?(\d{2})\.(\d{2})\.(\d{4}).*$~us', '$3-$2-$1', $n->nodeValue)
                    : null,
            ],
            'reviews' => [
                '@xpath'   => '//div[@itemprop="review" and @itemscope]',
                '@extract' => [
                    'author' => [
                        '@xpath' => './/div[@data-qa="patient_profile__node_author_link"]',
                        '@array' => false,
                    ],
                    'date' => [
                        '@xpath'   => './/*[@itemprop="datePublished"]/@content',
                        '@array'   => false,
                        '@extract' => fn($n) => $n?->nodeValue,
                    ],
                    'rating' => [
                        '@xpath'   => './/div[contains(@class,"review-card-tooltips__stars")]/span[contains(@class,"text-subtitle-2")]',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? (float) trim($n->nodeValue) : null,
                    ],
                    'direction' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__comments")]//span[contains(@class,"ui-icon-doctor")]/following-sibling::span[1]',
                        '@array' => false,
                    ],
                    'story' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__comment-title") and normalize-space()="История пациента"]/following-sibling::div[contains(@class,"b-review-card__comment")][1]',
                        '@array' => false,
                    ],
                    'liked' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__comment-title") and normalize-space()="Понравилось"]/following-sibling::div[contains(@class,"b-review-card__comment")][1]',
                        '@array' => false,
                    ],
                    'disliked' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__comment-title") and normalize-space()="Не понравилось"]/following-sibling::div[contains(@class,"b-review-card__comment")][1]',
                        '@array' => false,
                    ],
                    'reply' => [
                        '@xpath'   => './/div[contains(@class,"b-review-card__reply") and not(contains(@class,"b-review-card__reply-"))]',
                        '@array'   => false,
                        '@extract' => [
                            'date' => [
                                '@xpath' => './/div[contains(@class,"b-review-card__datetime_reply")]',
                                '@array' => false,
                            ],
                            'text' => [
                                '@xpath' => './/div[contains(@class,"b-review-card__reply-body-part")]',
                                '@array' => false,
                            ],
                        ],
                    ],
                    'clinicName' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__reply-container")]//div[contains(@class,"text-subtitle-2")]',
                        '@array' => false,
                    ],
                    'clinicAddress' => [
                        '@xpath' => './/div[contains(@class,"b-review-card__address")]',
                        '@array' => false,
                    ],
                ],
            ],
        ];
    }

    protected function getTemplate(): string { return 'https://prodoctorov.ru/kaliningrad/vrach/{vrach_id}/otzivi/'; }
}