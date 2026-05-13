<?php

namespace ST_system\API\Drivers\Acquiring;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

final class TBank extends IntegrationDriver {

    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://securepay.tinkoff.ru/v2/']; }

    private array $SETTINGS = [];

    
    public static function verifyNotificationToken(array $data, string $password): bool
    {
        $receivedToken = $data['Token'] ?? null;
        if ($receivedToken === null) return false;

        unset($data['Token']);
        $data['Password'] = $password;
        $data = array_filter($data, fn($v) => is_scalar($v));
        ksort($data);

        return hash_equals(strtolower($receivedToken), hash('sha256', implode('', array_map('strval', array_values($data)))));
    }

    
    public static function generateToken(array $params, string $password): string
    {
        unset($params['Token']);
        $params['Password'] = $password;
        $params = array_filter($params, fn($v) => is_scalar($v));
        ksort($params);
        return hash('sha256', implode('', array_map('strval', array_values($params))));
    }

    protected function __init(): void
    {
        $this->on('__construct', function(array $PARAMS) {
            $errors = Rule::object([
                'terminal_key' => 'required|string',
                'password'     => 'required|string',
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('before_curl_init', function($r, $m, $p, &$config) {
            $config['method']       = 'POST';
            $config['content_type'] = 'application/json';
        });

        $this->on('encode_request', function($method, &$params) {
            $params['TerminalKey'] = $this->SETTINGS['terminal_key'];
            $params['Token']       = static::generateToken($params, $this->SETTINGS['password']);
        });

        $this->on('prepare_response', function($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'];
                return;
            }
            $decoded = is_string($raw_data['response']) ? json_decode($raw_data['response'], true) : $raw_data['response'];
            if (is_array($decoded) && isset($decoded['Success']) && $decoded['Success'] === false)
                $raw_data['error'] = $decoded['Message'] ?? $decoded['Details'] ?? json_encode($decoded);
        });

        $this->registerMethodsMap([
            'Init' => [
                'params' => [
                    'Amount'          => Rule::create(fn(&$v) => is_numeric($v) && $v > 0)
                        ->handleError(fn($v) => 'Amount должен быть положительным числом (в копейках)')
                        ->after(fn(&$v) => $v = (int)$v)
                        ->skip(true),
                    'OrderId'         => 'required|string',
                    'Description'     => 'nullable|string',
                    'Recurrent'       => 'default:N|in:Y,N',
                    'CustomerKey'     => 'nullable|string',
                    'SuccessURL'      => 'nullable|url',
                    'FailURL'         => 'nullable|url',
                    'NotificationURL' => 'nullable|url',
                    'PayType'         => 'default:O|in:O,T',
                    'Language'        => 'default:ru|in:ru,en',
                    'DATA'            => 'nullable|array',
                    'Receipt'         => 'nullable|array',
                ],
            ],
            'Charge' => [
                'params' => [
                    'PaymentId' => 'required|string',
                    'RebillId'  => 'required|string',
                ],
            ],
            'GetState' => [
                'params' => [
                    'PaymentId' => 'required|string',
                ],
            ],
            'Cancel' => [
                'params' => [
                    'PaymentId' => 'required|string',
                    'Amount'    => 'nullable|int',
                ],
            ],
            'Confirm' => [
                'params' => [
                    'PaymentId' => 'required|string',
                    'Amount'    => 'nullable|int',
                ],
            ],
            'GetCardList' => [
                'params' => [
                    'CustomerKey' => 'required|string',
                ],
            ],
            'RemoveCard' => [
                'params' => [
                    'CustomerKey' => 'required|string',
                    'CardId'      => 'required|string',
                ],
            ],
            'Resend' => [
                'params' => [
                    'PaymentId' => 'required|string',
                ],
            ],
        ]);
    }
}
