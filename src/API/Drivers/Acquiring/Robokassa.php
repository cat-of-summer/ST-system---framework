<?php

namespace ST_system\API\Drivers\Acquiring;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;


final class Robokassa extends IntegrationDriver
{
    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://auth.robokassa.ru/']; }

    private array $SETTINGS = [];

    
    public static function hashSignature(string $data, string $algo = 'md5'): string
    {
        return hash($algo, $data);
    }

    
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

    
    public static function generatePaymentUrl(array $params): string
    {
        $base = 'https://auth.robokassa.ru/Merchant/Index.aspx';

        return $base . '?' . http_build_query($params);
    }

    
    public static function buildOpStateSignature(
        string $merchantLogin,
        $invoiceId,
        string $password2,
        string $algo = 'md5',
    ): string {
        return self::hashSignature("{$merchantLogin}:{$invoiceId}:{$password2}", $algo);
    }

    
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

    
    public static function mapStateCode(int $stateCode): string
    {
        switch ($stateCode) {
            case 100: return 'completed';   
            case 50:  return 'processing';  
            case 20:  return 'hold';        
            case 5:   return 'pending';     
            case 10:  return 'canceled';    
            case 60:  return 'refunded';    
            case 80:  return 'pending';     
            default:  return 'unknown';
        }
    }

    
    private static function buildShpSuffix(array $shpParams): string
    {
        if (empty($shpParams)) {
            return '';
        }

        
        uksort($shpParams, 'strnatcasecmp');

        $parts = '';
        foreach ($shpParams as $key => $value) {
            $parts .= ":{$key}={$value}";
        }

        return $parts;
    }

    
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

    
    protected function __init(): void
    {
        $this->on('__construct', function(array $PARAMS) {
            Rule::object([
                'merchant_login' => 'required|string',
                'password1'      => 'required|string',
                'password2'      => 'required|string',
                'hash_algo'      => Rule::create(fn(&$v) => $v === null || in_array($v, ['md5','sha1','sha256','sha384','sha512'], true))
                    ->handleError(fn($v) => 'Недопустимый алгоритм хеширования'),
                'test_mode'      => 'nullable|bool',
            ])->throwable()->apply($PARAMS);
            $PARAMS['hash_algo'] ??= 'md5';
            $PARAMS['test_mode']   = (bool)($PARAMS['test_mode'] ?? false);
            $this->SETTINGS = $PARAMS;
        });

        
        $this->on('before_curl_init', function ($r, $m, $p, &$config) {
            if (strpos($m, 'Recurring') !== false) {
                $config['method']       = 'POST';
                $config['content_type'] = 'application/x-www-form-urlencoded';
            } else {
                
                $config['method'] = 'GET';
            }
        });

        
        $this->on('encode_request', function ($request_url, $method, &$params) {
            if (strpos($method, 'Recurring') !== false) {
                
                $params['MerchantLogin'] = $this->SETTINGS['merchant_login'];

                $shpParams = self::extractShpParams($params);

                
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

            
            $params = http_build_query($params);
        });

        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'] ?: "HTTP {$raw_data['http_code']}";
                return;
            }

            
            if (strpos($method, 'OpStateExt') !== false) {
                $parsed = self::parseOpStateXml($raw_data['response']);
                if (isset($parsed['error'])) {
                    $raw_data['error'] = $parsed['error'];
                } elseif ($parsed['result_code'] !== 0) {
                    $raw_data['error'] = $parsed['result_description'] ?: "Result code: {$parsed['result_code']}";
                }
                $raw_data['response'] = $parsed;
            }
            
            elseif (strpos($method, 'Recurring') !== false) {
                $response = trim($raw_data['response']);
                
                if (stripos($response, 'OK') === false) {
                    $raw_data['error'] = $response;
                }
                $raw_data['response'] = ['raw' => $response, 'success' => stripos($response, 'OK') === 0];
            }
        });

        
        $this->on('decode_response', function ($method, $params, &$raw_data) {
            
        });

        
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

        
        foreach ($shpParams as $key => $value) {
            $query[$key] = $value;
        }

        return self::generatePaymentUrl($query);
    }

    
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

        
        foreach (($params['Shp_params'] ?? []) as $key => $value) {
            $callParams[$key] = $value;
        }

        return $this->call('Merchant/Recurring', $callParams);
    }

    
    public function verifyWebhook(array $data): bool
    {
        return self::verifyResultSignature(
            $data,
            $this->SETTINGS['password2'],
            $this->SETTINGS['hash_algo'],
        );
    }

    
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

    
    public function getSettings(): array
    {
        return $this->SETTINGS;
    }
}
