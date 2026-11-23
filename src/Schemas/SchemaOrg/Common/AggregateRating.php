<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

/**
 * Средняя оценка по реальным отзывам. Выводить только при rating_count > 0.
 */
final class AggregateRating extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'rating_value' => 'required|float|min:1|max:5',
            'rating_count' => 'required|int|min:1',
            'best_rating'  => 'sometimes|float',
            'worst_rating' => 'sometimes|float',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $s->field('rating_value'),
                'ratingCount' => $s->field('rating_count'),
            ];

            if ($s->field('best_rating') !== null) {
                $data['bestRating'] = $s->field('best_rating');
            }

            if ($s->field('worst_rating') !== null) {
                $data['worstRating'] = $s->field('worst_rating');
            }

            return $data;
        };
    }
}
