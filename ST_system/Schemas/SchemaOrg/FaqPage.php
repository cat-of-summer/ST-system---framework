<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;

final class FaqPage extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'questions' => [self::arrayOf('question'), 'required'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $questions = $s->field('questions') ?? [];

            $data = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(
                    static fn ($q) => $q->toArray(),
                    $questions
                ),
            ];

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>';
        };
    }
}
