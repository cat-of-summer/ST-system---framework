<?php

namespace ST_system\API\Drivers\Parsers\Prodoctorov;

use ST_system\API\Drivers\Parsers\DefaultParser;

final class ClinicPricesParser extends DefaultParser {

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'fetch_delay_ms' => 5000
        ]);
    }

    protected function __init(): void {
        parent::__init();

        $this->on('before_fetch', function($input) {
            
        });

        $this->on('after_redirect', function($input, $url, $effective, &$overrides) {

        });
    }

}