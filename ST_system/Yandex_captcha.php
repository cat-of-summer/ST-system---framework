<?php

namespace ST_system;

class Yandex_captcha {
    private $public_key;
    private $private_key;
    private $render_params_array;

    static private $THROW_ERRORS = false;
    static private $USE_CONSOLE_LOG_IN_DEFAULT_PARAM = false;
    static private $VERIFY_URL = 'https://smartcaptcha.yandexcloud.net/validate';

    static private $IS_CDN_INCLUDED;

    public function __construct($PARAMS = []) {
        /*
            [
                'public_key' => '',
                'private_key' => '',
                'render_params_array' => [
                    'default' => [ Параметры по умолчанию, собранные при создании конструктора
                        'smart-captcha' => false,
                        'hl' => 'ru',
                        'invisible' => 'false',
                        'onSuccess_JS_func' => function ($container_id) {
                            return "console.log('Успешное прохождения блока {$container_id}')";
                        },
                        'onFail_JS_func' => function ($container_id) {
                            return "console.log('Неудачное прохождения блока {$container_id}')";
                        },
                        'onExpired_JS_func' => function ($container_id) {
                            return "console.log('Истекло время существование для блока {$container_id}')";
                        }
                    ]
                ],
            ]
        */
        if (preg_match('/^[a-zA-Z0-9_-]{20,100}$/', $PARAMS['public_key']))
            $this->public_key = htmlspecialchars($PARAMS['public_key']);
        else
            if (self::$THROW_ERRORS) throw new \Exception('Invalid public key format');
    
        if (preg_match('/^[a-zA-Z0-9_-]{20,100}$/', $PARAMS['private_key']))
            $this->private_key = htmlspecialchars($PARAMS['private_key']);
        else
            if (self::$THROW_ERRORS) throw new \Exception('Invalid private key format');

        $this->render_params_array = array_merge(((isset($PARAMS['render_params_array']) && is_array($PARAMS['render_params_array'])) ? $PARAMS['render_params_array'] : []), [
            'default' => [
                'smart-captcha' => false,
                'hl' => 'ru',
                'invisible' => false,
                'onSuccess_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Успешное прохождения блока {$container_id}')" : '';
                },
                'onFail_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Неудачное прохождения блока {$container_id}')" : '';
                },
                'onExpired_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Истекло время существование для блока {$container_id}')" : '';
                }
            ]
        ]);
    }

    public function connect_CDN($PARAMS = []) {
        /*
            [
                'in_footer' => false,
                'return' => false, // Возврат либо вставить через echo
                'onload_JS_func' => function () {
                    return "console.log('Успешное подключение CDN!')";
                },
            ]
        */
        if (self::$IS_CDN_INCLUDED)
            return true;

        $onLoad_JS = (isset($PARAMS['onload_JS_func']) && is_callable($PARAMS['onload_JS_func']))
        ? $PARAMS['onload_JS_func']()
        : ((self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) 
            ? "console.log('Успешное подключение CDN!')" 
            : '');

        $where = $PARAMS['in_footer']
            ? 'body'
            : 'head';

        $result =  "
            <script type='text/javascript' defer>
                if (window.IS_CDN_INCLUDED == undefined) {
                    let script = document.createElement('script');
                    script.src = 'https://smartcaptcha.yandexcloud.net/captcha.js';
                    script.defer = true;
                    document.{$where}.appendChild(script);
                    window.IS_CDN_INCLUDED = false;

                    script.onload = function() {
                        window.IS_CDN_INCLUDED = true;
                        {$onLoad_JS}
                    };
                }
            </script>
        ";

        self::$IS_CDN_INCLUDED = true;

        if ($PARAMS['return'] === true)
            return $result;
        else
            echo $result;

        return true;
    }

    public function put_captcha($PARAMS = []) {
        /*
            [
                'render_params_key' => 'default', // Ключ для инициализации параметров по умолчанию, собранных при создании конструктора
                'use_smart_captcha' => false,
                'hl' => 'ru',
                'invisible' => false,
                'onSuccess_JS_func' => function ($container_id) {
                    return "console.log('Успешное прохождения блока {$container_id}')";
                },
                'onFail_JS_func' => function ($container_id) {
                    return "console.log('Неудачное прохождения блока {$container_id}')";
                },
                'onExpired_JS_func' => function ($container_id) {
                    return "console.log('Истекло время существование для блока {$container_id}')";
                }
            ]
        */

        if (empty($this->public_key) || !self::$IS_CDN_INCLUDED)
            return false;

        $container_id = 'captcha_' . md5(microtime(true) . rand());

        $render_params_key = isset($PARAMS['render_params_key']) ? htmlspecialchars($PARAMS['render_params_key']) : 'default';
        $RENDER_PARAMS = array_merge($this->render_params_array[$render_params_key], $PARAMS);
        
        $use_smart_captcha = ($RENDER_PARAMS['use_smart_captcha'] === true);
        $lang =  htmlspecialchars($RENDER_PARAMS['hl']);
        $is_invisible = (bool)$RENDER_PARAMS['invisible'] ? 'true' : 'false';

        $onSuccess_JS = $RENDER_PARAMS['onSuccess_JS_func']($container_id);
        $onFail_JS = $RENDER_PARAMS['onFail_JS_func']($container_id);
        $onExpired_JS = $RENDER_PARAMS['onExpired_JS_func']($container_id);

        if ($use_smart_captcha)
            echo "<div id='{$container_id}' class='smart-captcha' data-sitekey='{$this->public_key}'></div>";
        else
            echo "
                <div id='{$container_id}'></div>
                <script type='text/javascript' defer>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (window.IS_CDN_INCLUDED !== undefined) {
                            let check_CDN = setInterval(function() {
                                if (window.IS_CDN_INCLUDED == true) {
                                    clearInterval(check_CDN);

                                    let captcha_container = document.getElementById('{$container_id}');
                                    captcha_container.setAttribute('is_invalid', '');
                                    captcha_container.removeAttribute('is_valid');

                                    window.smartCaptcha.render(captcha_container, {
                                        sitekey: '{$this->public_key}',
                                        hl: '{$lang}',
                                        invisible: {$is_invisible},
                                        callback: function(response) {
                                            if (response) {
                                                captcha_container.setAttribute('is_valid', '');
                                                captcha_container.removeAttribute('is_invalid');
                                                {$onSuccess_JS}
                                            } else {
                                                {$onFail_JS}
                                            }
                                        },
                                        onFail: function() {
                                            {$onFail_JS}
                                        },
                                        onExpired: function() {
                                            {$onExpired_JS}
                                        }
                                    });
                                    
                                    if (!{$is_invisible})
                                        new MutationObserver((mutations, observer) => {
                                            mutations.forEach(mutation => {
                                                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                                                    captcha_container.removeAttribute('style');
                                                }
                                            });
                                        }).observe(captcha_container, {attributes: true, attributeFilter: ['style']});

                                }
                            }, 100);
                        }
                    });
                </script>
            ";

        return $container_id;
    }

    public function check_captcha($captcha_code) {
        $curl = curl_init(self::$VERIFY_URL);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
            'token' => $captcha_code,
            'secret' => $this->private_key,
        ]));

        $curl_response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            
            if (self::$THROW_ERRORS) 
                throw new \Exception("cURL error: $error");
            else
                return false;
        }
    
        $code_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $result = json_decode($curl_response);

        if ($code_response === 200 && $result->status === "ok")
            return true;
        
        if (self::$THROW_ERRORS)
            throw new \Exception("Captcha verification failed. $curl_response");
        else
            return false;
    }
}