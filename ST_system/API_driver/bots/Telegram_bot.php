<?php

namespace ST_system;

class Telegram_bot extends API_driver {
    protected const DEFAULT_POINT = 'https://api.telegram.org/bot';

    private $token;
    private $base_url;

    protected function __init() {
        
        $this->on('__construct', function($token, $base_url = null) {

            $params = [
                'token' => $token,
                'base_url' => $base_url,
            ];
            
            self::prepare_params([
                'token' => [new \Exception("Передан некорректный token"), fn($value) => !empty($value)],
                'base_url' => [((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'], fn($value) => filter_var($value, FILTER_VALIDATE_URL)],
            ], $params);

            $this->token = $params['token'];
            $this->base_url = $params['base_url'];
        });

    }

}