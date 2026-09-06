<?php

namespace ST_system\Schemas\OpenGraph;

use ST_system\Schemas\DefaultSchema;

final class Meta extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'type'                  => 'sometimes|string',
            'title'                 => 'required|string',
            'description'           => 'sometimes|string',
            'url'                   => 'sometimes|url',
            'image'                 => 'sometimes|url',
            'image_width'           => 'sometimes|int',
            'image_height'          => 'sometimes|int',
            'image_alt'             => 'sometimes|string',
            'site_name'             => 'sometimes|string',
            'locale'                => 'sometimes|string',
            'twitter_card'          => 'sometimes|string',
            'twitter_title'         => 'sometimes|string',
            'twitter_description'   => 'sometimes|string',
            'twitter_image'         => 'sometimes|url',
            'article_published_time' => 'sometimes|string',
            'article_modified_time'  => 'sometimes|string',
            'skip'                  => 'sometimes|array',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $og = [
                'og:type'        => $s->field('type') ?? 'website',
                'og:title'       => $s->field('title'),
                'og:description' => $s->field('description'),
                'og:url'         => $s->field('url'),
                'og:image'       => $s->field('image'),
                'og:image:width' => $s->field('image_width'),
                'og:image:height' => $s->field('image_height'),
                'og:image:alt'   => $s->field('image_alt'),
                'og:site_name'   => $s->field('site_name'),
                'og:locale'      => $s->field('locale'),
                'article:published_time' => $s->field('article_published_time'),
                'article:modified_time'  => $s->field('article_modified_time'),
            ];

            $twitter = [
                'twitter:card'        => $s->field('twitter_card'),
                'twitter:title'       => $s->field('twitter_title') ?? $s->field('title'),
                'twitter:description' => $s->field('twitter_description') ?? $s->field('description'),
                'twitter:image'       => $s->field('twitter_image') ?? $s->field('image'),
            ];

            if ($twitter['twitter:card'] === null && $twitter['twitter:image'] !== null) {
                $twitter['twitter:card'] = 'summary_large_image';
            }

            $skip = (array) ($s->field('skip') ?? []);

            $filter = static function (array $tags) use ($skip): array {
                $result = [];

                foreach ($tags as $name => $value) {
                    if ($value === null || $value === '' || in_array($name, $skip, true)) {
                        continue;
                    }

                    $result[$name] = (string) $value;
                }

                return $result;
            };

            return [
                'og'      => $filter($og),
                'twitter' => $filter($twitter),
            ];
        };
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = $s->toArray();
            $html = '';

            foreach ($data['og'] as $property => $value) {
                $html .= '<meta property="' . $property . '" content="'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }

            foreach ($data['twitter'] as $name => $value) {
                $html .= '<meta name="' . $name . '" content="'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }

            return rtrim($html, "\n");
        };
    }
}
