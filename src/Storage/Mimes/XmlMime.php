<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Main;
use ST_system\Storage\File;
use ST_system\Cache\Manager as Cache;
use ST_system\Traits\HasConfig;
use ST_system\Storage\Mimes\Traits\Parsable;

class XmlMime extends Mime {

    use Parsable;
}