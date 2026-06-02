<?php

namespace ST_system;

class CensorText {
    private array $bad_words = [];
    private array $normalization_map = [
        '6' => 'б', 
        '@' => 'а', 
        '3' => 'з', 
        '0' => 'о',
        '4' => 'ч', 
        '1' => 'и', 
        '!' => 'и', 
        '$' => 'с',
        '*' => '', 
        '.' => '', 
        ',' => '', 
        'ё' => 'е'
    ];
    private bool $use_normalization = true;

    private array $cache = [];

    public function __construct(array $bad_words, bool $use_normalization = true) {
        $this->bad_words = $bad_words;
        $this->use_normalization = $use_normalization;
    }

    public function normalization_map_add(array $map) {
        $this->normalization_map = array_merge($this->normalization_map, $map);
    }

    public function check(string $text) {
        $md5 = md5($text);

        if (!isset($this->cache[$md5]['check'])) {
            $this->cache[$md5]['check'] = [];

            foreach ($this->bad_words as $group => $words) {
                $this->cache[$md5]['check'][$group] = false;

                foreach ($words as $word) {
                    $pattern = '/\b' . preg_quote($word, '/') . '\w*/ui';
                    if (preg_match($pattern, $this->normalize($text))) {
                        $this->cache[$md5]['check'][$group] = true;
                        break;
                    }
                }
            }
        }

        return $this->cache[$md5]['check'];
    }

    public function checkAll(string $text) {
        $results = $this->check($text);

        return in_array(true, $results, true);
    }

    public function count(string $text) {
        $md5 = md5($text);

        if (!isset($this->cache[$md5]['count'])) {
            $results = [];

            foreach ($this->bad_words as $group => $words) {
                $matches = 0;

                foreach ($words as $word) {
                    $pattern = '/\b' . preg_quote($word, '/') . '\w*/ui';
                    $matches += preg_match_all($pattern, $this->normalize($text));
                }

                if ($matches > 0) $results[$group] = $matches;
            }

            $this->cache[$md5]['count'] = $results;
        }

        return $this->cache[$md5]['count'];
    }

    public function countAll(string $text) {
        return array_sum($this->count($text));
    }

    public function censor(string $text) {
        $md5 = md5($text);

        if (!isset($this->cache[$md5]['censor'])) {
            $normalized = $this->normalize($text);
            $censored = $normalized;

            foreach ($this->bad_words as $words) {
                foreach ($words as $word) {
                    $pattern = '/\b' . preg_quote($word, '/') . '\w*/ui';
                    $censored = preg_replace_callback($pattern, function ($match) {
                        return str_repeat('*', mb_strlen($match[0]));
                    }, $censored);
                }
            }

            $this->cache[$md5]['censor'] = $censored;
        }

        return $this->cache[$md5]['censor'];
    }

    private function normalize(string $text) {
        $text = mb_strtolower($text);

        if ($this->use_normalization)
            $text = strtr($text, $this->normalization_map);
        
        return $text;
    }
}
