<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;
use ST_system\Rule;

final class Review extends DefaultSchema
{
    protected static function _init(): void
    {
        if (!Rule::get('boolToString')) {
            Rule::create(fn (&$v): bool => (bool)($v = $v ? 'true' : 'false'))
                ->alias('boolToString');
        }
    }

    protected static function getFields(): array
    {
        return [
            'date'           => 'required|string',
            'checked'        => 'sometimes|bool|boolToString',
            'used_in_rating' => 'sometimes|bool|boolToString',
            'author'         => 'required|string',
            'author_id'      => 'sometimes|string',
            'author_picture' => 'sometimes|url',
            'url'            => 'sometimes|url',
            'comment'        => 'required|string',
            'grade'          => 'sometimes|float',
            'positive'       => 'sometimes|string',
            'negative'       => 'sometimes|string',
            'response'       => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $xml  = '<review>';
            $xml .= '<date>' . self::xml($s->field('date')) . '</date>';
            if ($s->field('checked') !== null) {
                $xml .= '<checked>' . self::xml($s->field('checked')) . '</checked>';
            }
            if ($s->field('used_in_rating') !== null) {
                $xml .= '<used_in_rating>' . self::xml($s->field('used_in_rating')) . '</used_in_rating>';
            }
            $xml .= '<author>' . self::xml($s->field('author')) . '</author>';
            if ($s->field('author_id') !== null) {
                $xml .= '<author_id>' . self::xml($s->field('author_id')) . '</author_id>';
            }
            if ($s->field('author_picture') !== null) {
                $xml .= '<author_picture>' . self::xml($s->field('author_picture')) . '</author_picture>';
            }
            if ($s->field('url') !== null) {
                $xml .= '<url>' . self::xml($s->field('url')) . '</url>';
            }
            $xml .= '<comment>' . self::xml($s->field('comment')) . '</comment>';
            if ($s->field('grade') !== null) {
                $xml .= '<grade>' . self::xml($s->field('grade')) . '</grade>';
            }
            if ($s->field('positive') !== null) {
                $xml .= '<positive>' . self::xml($s->field('positive')) . '</positive>';
            }
            if ($s->field('negative') !== null) {
                $xml .= '<negative>' . self::xml($s->field('negative')) . '</negative>';
            }
            if ($s->field('response') !== null) {
                $xml .= '<response>' . self::xml($s->field('response')) . '</response>';
            }
            $xml .= '</review>';
            return $xml;
        };
    }
}
