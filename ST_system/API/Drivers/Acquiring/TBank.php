<?php

namespace ST_system\API\Drivers\Acquiring;

use \ST_system\API\IntegrationDriver;

final class TBank extends IntegrationDriver {

    protected const DEFAULT_POINT = 'https://securepay.tinkoff.ru/v2/';

    private array $SETTINGS = [];

    /**
     * Verify the Token signature of an incoming T-Bank notification.
     *
     * Algorithm (T-Bank docs):
     * 1. Exclude the "Token" key from the payload.
     * 2. Add the "Password" value with key "Password".
     * 3. Sort all pairs alphabetically by key.
     * 4. Concatenate values (as strings) in sorted order.
     * 5. SHA-256 hash of the resulting string.
     *
     * @param array  $data     The full notification payload (including "Token").
     * @param string $password Terminal password.
     * @return bool
     */
    public static function verifyNotificationToken(array $data, string $password): bool
    {
        $receivedToken = $data['Token'] ?? null;
        if ($receivedToken === null) {
            return false;
        }

        unset($data['Token']);
        $data['Password'] = $password;

        // Remove nested arrays/objects — T-Bank documentation says
        // only scalar values participate in token generation.
        $data = array_filter($data, fn($v) => is_scalar($v));

        ksort($data);

        $concatenated = implode('', array_map('strval', array_values($data)));

        return hash_equals(
            strtolower($receivedToken),
            hash('sha256', $concatenated)
        );
    }

    /**
     * Generate the Token value for an outgoing API request.
     *
     * Same algorithm as verification but we produce the hash ourselves.
     *
     * @param array  $params   Request parameters (without Token).
     * @param string $password Terminal password.
     * @return string SHA-256 hex digest.
     */
    public static function generateToken(array $params, string $password): string
    {
        unset($params['Token']);
        $params['Password'] = $password;

        $params = array_filter($params, fn($v) => is_scalar($v));

        ksort($params);

        $concatenated = implode('', array_map('strval', array_values($params)));

        return hash('sha256', $concatenated);
    }

    protected function __init()
    {
        // ------------------------------------------------------------------
        // Constructor hook — capture terminal_key & password
        // ------------------------------------------------------------------
        $this->on('__construct', function (array $PARAMS) {
            $this->SETTINGS = static::prepare_params([
                'terminal_key' => '*string',
                'password'     => '*string',
            ], $PARAMS);
        });

        // ------------------------------------------------------------------
        // All requests are POST with JSON body
        // ------------------------------------------------------------------
        $this->on('before_curl_init', function ($r, $m, $p, &$config) {
            $config['method']       = 'POST';
            $config['content_type'] = 'application/json';
        });

        // ------------------------------------------------------------------
        // Inject TerminalKey + Token into every request body
        // ------------------------------------------------------------------
        $this->on('encode_request', function ($method, &$params) {
            $params['TerminalKey'] = $this->SETTINGS['terminal_key'];
            $params['Token'] = static::generateToken($params, $this->SETTINGS['password']);
        });

        // ------------------------------------------------------------------
        // Treat non-2xx or Success===false as an error
        // ------------------------------------------------------------------
        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'];
                return;
            }

            $decoded = is_string($raw_data['response'])
                ? json_decode($raw_data['response'], true)
                : $raw_data['response'];

            if (is_array($decoded) && isset($decoded['Success']) && $decoded['Success'] === false) {
                $raw_data['error'] = $decoded['Message']
                    ?? $decoded['Details']
                    ?? json_encode($decoded);
            }
        });

        // ------------------------------------------------------------------
        // Register T-Bank Acquiring API methods
        // ------------------------------------------------------------------
        $this->register_methods_map([

            // Create a payment session. Returns PaymentURL for redirect.
            'Init' => [
                'params' => [
                    'Amount'          => $this->extend_rule('*int', [
                        'after' => fn($v) => (int) $v, // must be integer (kopecks)
                    ]),
                    'OrderId'         => '*string',
                    'Description'     => 'string',
                    'Recurrent'       => ['N', fn($v) => in_array($v, ['Y', 'N'], true)],
                    'CustomerKey'     => 'string',
                    'SuccessURL'      => 'url',
                    'FailURL'         => 'url',
                    'NotificationURL' => 'url',
                    'PayType'         => ['O', fn($v) => in_array($v, ['O', 'T'], true)], // O = one-step, T = two-step
                    'Language'        => ['ru', fn($v) => in_array($v, ['ru', 'en'], true)],
                    'DATA'            => 'array',
                    'Receipt'         => 'array',
                ],
            ],

            // Recurring (auto) charge using saved RebillId.
            'Charge' => [
                'params' => [
                    'PaymentId' => '*string',
                    'RebillId'  => '*string',
                ],
            ],

            // Get current payment state.
            'GetState' => [
                'params' => [
                    'PaymentId' => '*string',
                ],
            ],

            // Cancel or refund a payment (full or partial).
            'Cancel' => [
                'params' => [
                    'PaymentId' => '*string',
                    'Amount'    => 'int', // optional: partial refund in kopecks
                ],
            ],

            // Confirm a two-step (holded) payment.
            'Confirm' => [
                'params' => [
                    'PaymentId' => '*string',
                    'Amount'    => 'int',
                ],
            ],

            // Get saved cards for a customer.
            'GetCardList' => [
                'params' => [
                    'CustomerKey' => '*string',
                ],
            ],

            // Remove a saved card.
            'RemoveCard' => [
                'params' => [
                    'CustomerKey' => '*string',
                    'CardId'      => '*string',
                ],
            ],

            // Resend notifications for a payment.
            'Resend' => [
                'params' => [
                    'PaymentId' => '*string',
                ],
            ],
        ]);
    }
}
