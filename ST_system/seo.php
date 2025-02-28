AddEventHandler("main", "OnEpilog", function() {
    if (\Bitrix\Main\Loader::includeModule('iblock')) {
      $IBLOCK_ID = 15;
      $cacheTime = 36000;

      $cache = \Bitrix\Main\Data\Cache::createInstance();

      if ($cache->initCache($cacheTime, md5($current_url))) {
          $rules = $cache->getVars();
      } elseif ($cache->startDataCache()) {
        $r_rules = \CIBlockElement::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'PROPERTY_LINK', 'PROPERTY_TITLE', 'PROPERTY_DESCRIPTION']
        );

        while ($rule = $r_rules->Fetch()) {
            $rules[] = [
                'ID'          => $rule['ID'],
                'LINK'        => $rule['PROPERTY_LINK_VALUE'],
                'TITLE'       => $rule['PROPERTY_TITLE_VALUE'],
                'DESCRIPTION' => $rule['PROPERTY_DESCRIPTION_VALUE']['TEXT'],
            ];
        }

        if (!empty($rules))
            $cache->endDataCache($rules);
        else
            $cache->abortDataCache();
      }

      if (!empty($rules)) {
        global $APPLICATION;

        $current_url = parse_url(\Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getRequestUri(), PHP_URL_PATH);

        foreach ($rules as $rule) {
          if ($rule['LINK'] == $current_url) {
            $APPLICATION->SetPageProperty("title", $rule['TITLE']);
            $APPLICATION->SetPageProperty("description", $rule['DESCRIPTION']);

            break;
          }
        }
      }

    }
});