<?php

namespace ST_system\Schemas\SchemaOrg\FaqPage;

use ST_system\Schemas\DefaultSchema;

final class Question extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'question' => 'required|string|strip_tags',
            'answer'   => 'required|string|strip_tags',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            return [
                '@type' => 'Question',
                'name'  => $s->field('question'),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $s->field('answer'),
                ],
            ];
        };
    }
}
