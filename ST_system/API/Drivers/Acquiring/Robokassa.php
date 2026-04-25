<?php

namespace ST_system\API\Drivers\Acquiring;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

/**
 * Robokassa Acquiring API Driver.
 *
 * Supports:
 * - Payment URL generation (redirect to Robokassa payment page)
 * - Recurring (periodic) payments via PreviousInvoiceID
 * - Operation state check via OpStateExt XML interface
 * - Webhook (ResultURL) signature verification
 *
 * @see https://docs.robokassa.ru/ru/pay-interface
 * @see https://docs.robokassa.ru/ru/recurring-payments
 * @see https://docs.robokassa.ru/ru/notifications-and-redirects
 * @see https://docs.robokassa.ru/ru/xml-interfaces
 */
final class Robokassa extends IntegrationDriver
{
    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://auth.robokassa.ru/']; }

    private array $SETTINGS = [];

    // ------------------------------------------------------------------
    // Static helpers (used outside of ->call() flow)
    // ------------------------------------------------------------------

    /**
     * Compute a hash using the configured algorithm.
     *
     * @param string $data  The concatenated string to hash.
     * @param string $algo  Hash algorithm name: 'md5', 'sha256', 'sha1', etc.
     */
    public static function hashSignature(string $data, string $algo = 'md5'): string
    {
        return hash($algo, $data);
    }

    /**
     * Build the SignatureValue for the payment initialisation request.
     *
     * Formula: MerchantLogin:OutSum:InvId:Password#1[:Shp_*]
     * (Shp_* parameters are sorted alphabetically and appended as :Shp_key=value)
     *
     * @param  string            $merchantLogin
     * @param  string            $outSum       e.g. "990.00"
     * @param  int|string        $invId        Invoice ID (integer)
     * @param  string            $password1    Пароль#1
     * @param  array<string,string> $shpParams Shp_* params (keys WITHOUT the Shp_ prefix are fine — they will be prefixed)
     * @param  string            $algo         Hash algorithm
     * @param  bool              $recurring    Whether the Recurring flag is set (not included in signature)
     */
    public static function buildInitSignature(
        string $merchantLogin,
        string $outSum,
        $invId,
        string $password1,
        array $shpParams = [],
        string $algo = 'md5',
    ): string {
        $base = "{$merchantLogin}:{$outSum}:{$invId}:{$password1}";

        $base .= self::buildShpSuffix($shpParams);

        return self::hashSignature($base, $algo);
    }

    /**
     * Build the SignatureValue expected in ResultURL (webhook) notifications.
     *
     * Formula: OutSum:InvId:Пароль#2[:Shp_*]
     */
    public static function buildResultSignature(
        string $outSum,
        $invId,
        string $password2,
        array $shpParams = [],
        string $algo = 'md5',
    ): string {
        $base = "{$outSum}:{$invId}:{$password2}";

        $base .= self::buildShpSuffix($shpParams);

        return self::hashSignature($base, $algo);
    }

    /**
     * Verify the SignatureValue received in a ResultURL webhook callback.
     *
     * @param  array  $data      Webhook POST data (OutSum, InvId, SignatureValue, Shp_* …).
     * @param  string $password2 Пароль#2 from Robokassa settings.
     * @param  string $algo      Hash algorithm configured in the Robokassa merchant panel.
     */
    public static function verifyResultSignature(array $data, string $password2, string $algo = 'md5'): bool
    {
        $receivedSignature = $data['SignatureValue'] ?? null;

        if ($receivedSignature === null) {
            return false;
        }

        $outSum = (string) ($data['OutSum'] ?? '');
        $invId  = (string) ($data['InvId'] ?? '');

        $shpParams = self::extractShpParams($data);

        $expected = self::buildResultSignature($outSum, $invId, $password2, $shpParams, $algo);

        return hash_equals(strtolower($expected), strtolower($receivedSignature));
    }

    /**
     * Build the SignatureValue for SuccessURL redirect verification.
     *
     * Formula: OutSum:InvId:Пароль#1[:Shp_*]
     */
    public static function buildSuccessSignature(
        string $outSum,
        $invId,
        string $password1,
        array $shpParams = [],
        string $algo = 'md5',
    ): string {
        $base = "{$outSum}:{$invId}:{$password1}";

        $base .= self::buildShpSuffix($shpParams);

        return self::hashSignature($base, $algo);
    }

    /**
     * Generate the full payment URL to redirect the user to Robokassa.
     *
     * @param array $params Keys: MerchantLogin, OutSum, InvId, Description,
     *                      SignatureValue, IsTest, Recurring, Email, Shp_* …
     * @return string  Full URL for GET redirect.
     */
    public static function generatePaymentUrl(array $params): string
    {
        $base = 'https://auth.robokassa.ru/Merchant/Index.aspx';

        return $base . '?' . http_build_query($params);
    }

    /**
     * Build the OpStateExt signature.
     *
     * Formula: MerchantLogin:InvoiceID:Пароль#2
     */
    public static function buildOpStateSignature(
        string $merchantLogin,
        $invoiceId,
        string $password2,
        string $algo = 'md5',
    ): string {
        return self::hashSignature("{$merchantLogin}:{$invoiceId}:{$password2}", $algo);
    }

    /**
     * Extract Shp_* parameters from an array.
     *
     * @return array<string,string>  Keys prefixed with Shp_ (original case).
     */
    public static function extractShpParams(array $data): array
    {
        $shp = [];
        foreach ($data as $key => $value) {
            if (stripos($key, 'Shp_') === 0 || stripos($key, 'shp_') === 0) {
                $shp[$key] = (string) $value;
            }
        }
        return $shp;
    }

    /**
     * Map Robokassa OpStateExt State.Code to an internal status string.
     *
     * @param int $stateCode  Robokassa State.Code value.
     */
    public static function mapStateCode(int $stateCode): string
    {
        return match ($stateCode) {
            100     => 'completed',   // Платёж проведён успешно
            50      => 'processing',  // Средства получены, зачисление в процессе
            20      => 'hold',        // HOLD
            5       => 'pending',     // Операция инициализирована
            10      => 'canceled',    // Операция отменена
            60      => 'refunded',    // Отказ в зачислении, возврат
            80      => 'pending',     // Приостановлено (проверка безопасности)
            default => 'unknown',
        };
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Build the Shp_* suffix for a signature string.
     *
     * Parameters are sorted alphabetically by key and appended as :Shp_key=value.
     */
    private static function buildShpSuffix(array $shpParams): string
    {
        if (empty($shpParams)) {
            return '';
        }

        // Sort by key (case-insensitive, alphabetical)
        uksort($shpParams, 'strnatcasecmp');

        $parts = '';
        foreach ($shpParams as $key => $value) {
            $parts .= ":{$key}={$value}";
        }

        return $parts;
    }

    /**
     * Parse an XML string returned by OpStateExt into an associative array.
     */
    private static function parseOpStateXml(string $xml): array
    {
        libxml_use_internal_errors(true);

        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return ['error' => 'Failed to parse XML response'];
        }

        $result = [
            'result_code'        => (int) ($doc->Result->Code ?? -1),
            'result_description' => (string) ($doc->Result->Description ?? ''),
        ];

        if (isset($doc->State)) {
            $result['state_code']    = (int) ($doc->State->Code ?? -1);
            $result['request_date']  = (string) ($doc->State->RequestDate ?? '');
            $result['state_date']    = (string) ($doc->State->StateDate ?? '');
        }

        if (isset($doc->Info)) {
            $result['inc_curr_label']  = (string) ($doc->Info->IncCurrLabel ?? '');
            $result['inc_sum']         = (string) ($doc->Info->IncSum ?? '');
            $result['inc_account']     = (string) ($doc->Info->IncAccount ?? '');
            $result['payment_method']  = (string) ($doc->Info->PaymentMethod->Code ?? '');
            $result['out_curr_label']  = (string) ($doc->Info->OutCurrLabel ?? '');
            $result['out_sum']         = (string) ($doc->Info->OutSum ?? '');
            $result['op_key']          = (string) ($doc->Info->OpKey ?? '');
        }

        if (isset($doc->UserField->Field)) {
            $fields = [];
            foreach ($doc->UserField->Field as $field) {
                $fields[(string) $field->Name] = (string) $field->Value;
            }
            $result['user_fields'] = $fields;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // IntegrationDriver implementation
    // ------------------------------------------------------------------

    protected function __init(): void
    {
        $this->on('__construct', function(array $PARAMS) {
            $errors = Rule::object([
                'merchant_login' => 'required|string',
                'password1'      => 'required|string',
                'password2'      => 'required|string',
                'hash_algo'      => Rule::create(fn(&$v) => $v === null || in_array($v, ['md5','sha1','sha256','sha384','sha512'], true))
                    ->handleError(fn($v) => 'Недопустимый алгоритм хеширования'),
                'test_mode'      => 'nullable|bool',
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $PARAMS['hash_algo'] ??= 'md5';
            $PARAMS['test_mode']   = (bool)($PARAMS['test_mode'] ?? false);
            $this->SETTINGS = $PARAMS;
        });

        // ------------------------------------------------------------------
        // Recurring endpoint — POST form-encoded
        // ------------------------------------------------------------------
        $this->on('before_curl_init', function ($r, $m, $p, &$config) {
            if (str_contains($m, 'Recurring')) {
                $config['method']       = 'POST';
                $config['content_type'] = 'application/x-www-form-urlencoded';
            } else {
                // OpStateExt — GET
                $config['method'] = 'GET';
            }
        });

        // NOTE: trigger('encode_request', $request_url, $method, $params, $config)
        // Listener receives args in that order, so correct signature is:
        // function ($request_url, $method, &$params)
        $this->on('encode_request', function ($request_url, $method, &$params) {
            if (str_contains($method, 'Recurring')) {
                // Add merchant login & signature to recurring request
                $params['MerchantLogin'] = $this->SETTINGS['merchant_login'];

                $shpParams = self::extractShpParams($params);

                // PreviousInvoiceID is NOT included in signature
                $params['SignatureValue'] = self::buildInitSignature(
                    $this->SETTINGS['merchant_login'],
                    (string) $params['OutSum'],
                    (string) $params['InvoiceID'],
                    $this->SETTINGS['password1'],
                    $shpParams,
                    $this->SETTINGS['hash_algo'],
                );

                if ($this->SETTINGS['test_mode']) {
                    $params['IsTest'] = '1';
                }
            }

            // Always URL-encode params.
            // For POST (Recurring): results in application/x-www-form-urlencoded body.
            // For GET (OpStateExt): results in a proper query string (prevents "Array to string").
            $params = http_build_query($params);
        });

        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'] ?: "HTTP {$raw_data['http_code']}";
                return;
            }

            // OpStateExt returns XML
            if (str_contains($method, 'OpStateExt')) {
                $parsed = self::parseOpStateXml($raw_data['response']);
                if (isset($parsed['error'])) {
                    $raw_data['error'] = $parsed['error'];
                } elseif ($parsed['result_code'] !== 0) {
                    $raw_data['error'] = $parsed['result_description'] ?: "Result code: {$parsed['result_code']}";
                }
                $raw_data['response'] = $parsed;
            }
            // Recurring endpoint returns plain text like "OK{InvId}" or error XML
            elseif (str_contains($method, 'Recurring')) {
                $response = trim($raw_data['response']);
                // Successful response starts with "OK"
                if (stripos($response, 'OK') === false) {
                    $raw_data['error'] = $response;
                }
                $raw_data['response'] = ['raw' => $response, 'success' => stripos($response, 'OK') === 0];
            }
        });

        // Response was already decoded into an array by prepare_response (for both OpStateExt
        // and Recurring). Registering this listener causes trigger('decode_response') to return
        // null instead of false, which makes call() take the `else` branch and return
        // $raw_data['response'] directly — skipping the json_decode attempt on an already-decoded array.
        $this->on('decode_response', function ($method, $params, &$raw_data) {
            // No-op: the response is already in the correct format from prepare_response.
        });

        // ------------------------------------------------------------------
        // Register API methods
        // ------------------------------------------------------------------

        $this->registerMethodsMap([
            'Merchant/Recurring' => [
                'params' => [
                    'InvoiceID'         => 'required|string',
                    'PreviousInvoiceID' => 'required|string',
                    'OutSum'            => Rule::create(fn(&$v) => is_numeric($v) && $v > 0)
                        ->handleError(fn($v) => 'OutSum должен быть положительным числом')
                        ->after(fn(&$v) => $v = number_format((float)$v, 2, '.', ''))
                        ->skip(true),
                    'Description'       => 'nullable|string',
                    'Email'             => 'nullable|email',
                ],
            ],
            'Merchant/WebService/Service.asmx/OpStateExt' => [
                'params' => [
                    'MerchantLogin' => 'required|string',
                    'InvoiceID'     => 'required|string',
                    'Signature'     => 'required|string',
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Convenience methods (use settings from constructor)
    // ------------------------------------------------------------------

    /**
     * Build a full payment URL for first-time (and optionally recurring) payment.
     *
     * @param  array{
     *     OutSum: string|float,
     *     InvId: int|string,
     *     Description?: string,
     *     Email?: string,
     *     Recurring?: bool,
     *     Shp_params?: array<string,string>,
     * } $params
     * @return string Payment URL to redirect the user.
     */
    public function createPaymentUrl(array $params): string
    {
        $outSum = number_format((float) ($params['OutSum'] ?? 0), 2, '.', '');
        $invId  = (string) ($params['InvId'] ?? '');

        $shpParams = $params['Shp_params'] ?? [];

        $signature = self::buildInitSignature(
            $this->SETTINGS['merchant_login'],
            $outSum,
            $invId,
            $this->SETTINGS['password1'],
            $shpParams,
            $this->SETTINGS['hash_algo'],
        );

        $query = [
            'MerchantLogin'  => $this->SETTINGS['merchant_login'],
            'OutSum'         => $outSum,
            'InvId'          => $invId,
            'Description'    => $params['Description'] ?? '',
            'SignatureValue' => $signature,
        ];

        if (!empty($params['Email'])) {
            $query['Email'] = $params['Email'];
        }

        if (!empty($params['Recurring'])) {
            $query['Recurring'] = 'true';
        }

        if ($this->SETTINGS['test_mode']) {
            $query['IsTest'] = '1';
        }

        // Append Shp_* parameters
        foreach ($shpParams as $key => $value) {
            $query[$key] = $value;
        }

        return self::generatePaymentUrl($query);
    }

    /**
     * Check operation state via OpStateExt.
     *
     * @param  int|string $invoiceId  The InvId (= Payment.id).
     * @return array  Parsed XML response with state_code, op_key, etc.
     */
    public function getOperationState($invoiceId): array
    {
        $signature = self::buildOpStateSignature(
            $this->SETTINGS['merchant_login'],
            (string) $invoiceId,
            $this->SETTINGS['password2'],
            $this->SETTINGS['hash_algo'],
        );

        return $this->call('Merchant/WebService/Service.asmx/OpStateExt', [
            'MerchantLogin' => $this->SETTINGS['merchant_login'],
            'InvoiceID'     => (string) $invoiceId,
            'Signature'     => $signature,
        ]);
    }

    /**
     * Initiate a recurring (child) payment.
     *
     * @param  array{
     *     InvoiceID: int|string,
     *     PreviousInvoiceID: int|string,
     *     OutSum: string|float,
     *     Description?: string,
     *     Email?: string,
     *     Shp_params?: array<string,string>,
     * } $params
     * @return array  Response with 'success' boolean and 'raw' text.
     */
    public function chargeRecurring(array $params): array
    {
        $callParams = [
            'InvoiceID'         => (string) $params['InvoiceID'],
            'PreviousInvoiceID' => (string) $params['PreviousInvoiceID'],
            'OutSum'            => (string) $params['OutSum'],
        ];

        if (!empty($params['Description'])) {
            $callParams['Description'] = $params['Description'];
        }

        if (!empty($params['Email'])) {
            $callParams['Email'] = $params['Email'];
        }

        // Shp_* params
        foreach (($params['Shp_params'] ?? []) as $key => $value) {
            $callParams[$key] = $value;
        }

        return $this->call('Merchant/Recurring', $callParams);
    }

    /**
     * Verify a webhook notification signature with this driver's settings.
     *
     * @param array $data  Webhook POST payload.
     */
    public function verifyWebhook(array $data): bool
    {
        return self::verifyResultSignature(
            $data,
            $this->SETTINGS['password2'],
            $this->SETTINGS['hash_algo'],
        );
    }

    /**
     * Compute the expected webhook hash for diagnostics.
     *
     * Returns ONLY the hash — the password is never exposed.
     *
     * @param array $data  Webhook POST payload (OutSum, InvId, Shp_* …).
     * @return string  The expected SignatureValue for these params.
     */
    public function computeExpectedWebhookHash(array $data): string
    {
        $outSum    = (string) ($data['OutSum'] ?? '');
        $invId     = (string) ($data['InvId'] ?? '');
        $shpParams = self::extractShpParams($data);

        return self::buildResultSignature(
            $outSum,
            $invId,
            $this->SETTINGS['password2'],
            $shpParams,
            $this->SETTINGS['hash_algo'],
        );
    }

    /**
     * Get the configured settings (for debugging / testing).
     */
    public function getSettings(): array
    {
        return $this->SETTINGS;
    }
}
