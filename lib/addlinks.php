<?php

namespace Orwo\SeoSmartFilter;

class AddLinks extends \Orwo\SeoSmartFilter\SetFilter
{
    /**
     * [beforetUpdateElement создаем ссылки на стадии сохраниения сео элемента]
     * @param  [type] $arFields [Данные пришедшие с события]
     */
    public function beforetUpdateElement($arFields = [])
    {
        // Подключим нужные нам модули для работы
        \Bitrix\Main\Loader::includeModule("iblock");
        \Bitrix\Main\Loader::includeModule("highloadblock");

        // Получаем настройки модуля
        $seoIblock      = parent::moduleIblockID();     // ID сео ифноблока
        $catalogIblock  = parent::catalogIblockID(); // ID каталога ифноблока
        $filterSef      = parent::filterSef();       // Стандартный ЧПУ фильтра
        // Применяем событие только для сео-инфоблока
        if ($arFields['IBLOCK_ID'] != $seoIblock) {
            return;
        }

        $paramTranslitURL = array("replace_space" => "_", "replace_other" => "_");
        /**
         * @var $arPropsEvent - массив с ключем кодом свойств, вместо ID
         */
        $resPropertySeo = \CIBlockProperty::GetList([], array('IBLOCK_ID' => $seoIblock));
        while ($arPropName = $resPropertySeo->Fetch()) {
            $arPropsEvent[$arPropName['CODE']] = $arFields['PROPERTY_VALUES'][$arPropName['ID']];
        }

        /**
         * [Работа с типом свойств. Добавляем в массив доп. данные]
         */
        foreach ($arPropsEvent['PROP_FILTER'] as $key => $value) {
            $propType = \CIBlockProperty::GetByID($value['VALUE'], $catalogIblock)->GetNext();
            $arPropsEvent['PROP_FILTER'][$key]['PROPERTY_TYPE'] = $propType['PROPERTY_TYPE'];
            // Справочник
            if ($propType['USER_TYPE'] == 'directory') {
                $setMessage['error'][] = 'Невозможно обработать типа "Справочник"';
                return $setMessage;
            }
            // Если свойство привязка, то получаем ID инфоблока привязки
            if ($propType['PROPERTY_TYPE'] == 'G' || $propType['PROPERTY_TYPE'] == 'E') {
                $arPropsEvent['PROP_FILTER'][$key]['SUB_IBLOCK'] = \CIBlockProperty::GetByID($value['VALUE'], $catalogIblock)->GetNext()['LINK_IBLOCK_ID'];
            }
            // С файлами не работаем
            if ($propType['PROPERTY_TYPE'] == 'F') {
                $setMessage['error'][] = 'Невозможно обработать строку типа "Файл"';
                return $setMessage;
            }
        }

        /**
         * [delAllLinks Удаление ссылок для перезаписи]
         */
        if (!empty($_REQUEST['recteate'])) {
            parent::delAllLinks($arFields['ID']);
        }

        // Событие перед записью ссылок
        $event = new \Bitrix\Main\Event("orwo.seosmartfilter", "OnPropLinkCreate", [$arPropsEvent]);
        $event->send();
        foreach ($event->getResults() as $eventResult) {
            if ($eventResult->getType() == \Bitrix\Main\EventResult::ERROR) { // если обработчик вернул ошибку, ничего не делаем
                continue;
            }
            $arPropsEvent = $eventResult->getParameters();
        }

        /**
         * [Берем первый элемент из массива через reset]
         * @var $newTagSef  -  [Берем новое правило формирование URL]
         * @var $tagNameVal -  [Получаем имя для тега]
         */
        $newTagSef    = reset($arPropsEvent['NEW_SEF'])['VALUE'];
        $tagNameVal   = reset($arPropsEvent['NAME_TAG'])['VALUE'];

        /**
         * [Собираем заготовки под ссылки исходя из выбранных разделов]
         * @var $urlList - Список шаблонных ссылок раздела
         */
        $res = \CIBlock::GetByID($catalogIblock);
        if ($ar_res = $res->GetNext()) {//Если в настройках чпу #SECTION_CODE#, то ставим его. Иначе #SECTION_CODE_PATH#
            if (preg_match('/\#SECTION_CODE\#/', $ar_res["SECTION_PAGE_URL"])) {
                $sectionPageUrlPath = "#SECTION_CODE#/";
            }else {
                $sectionPageUrlPath = "#SECTION_CODE_PATH#/";
            }
        }
        foreach ($arPropsEvent['SET_ID_LIST'] as $sectionID) {
            $res = \CIBlockSection::GetByID($sectionID['VALUE']);
            while ($arRes = $res->GetNext()) {
                // Активен ли раздел
                if ($arRes['ACTIVE'] == "Y" && !empty($arRes['SECTION_PAGE_URL'])) {
                    $urlList[] = array(
                        'OLD_LINK' =>  str_replace($sectionPageUrlPath, $arRes['SECTION_PAGE_URL'], $filterSef),
                        'NEW_LINK' => substr($arRes['SECTION_PAGE_URL'], 0, -1).$newTagSef,//str_replace("#SECTION_CODE_PATH#/", $arRes['SECTION_PAGE_URL'], $newTagSef),
                        'ID' => $arRes['ID']
                    );
                }
            }
        }
   

        $arLink = [];
        $templateLinks = false;
        /**
         * [Работа с подготовленными данными]
         */
        foreach ($urlList as $compliteURL) {
            $link['ID_CAT'] = $arFields['ID'];
            $link['REDIRECT'] = (!empty($arPropsEvent['REDIRECT']) ? 1 : 0);
            $link['SECTION_ID'] = $compliteURL['ID'];
            $arProperties = [];
            $count = 0;

            foreach (array_values($arPropsEvent['PROP_FILTER']) as $kProp => $prop) {
                if (empty($prop['VALUE']) && empty($prop['DESCRIPTION'])) {
                    continue;
                }
                $count++;
                $prop['VALUE'] = mb_strtoupper($prop['VALUE']);
                /*--------ШАБЛОНЫЕ ССЫЛКИ-------*/
                if ($prop['DESCRIPTION'] == "{FILTER_VALUE}") {
                    $templateLinks = true;
                    // Запрос на выборку всех вариантов
                    $rsProps = \CIBlockElement::GetList(array(), array("IBLOCK_ID" => $catalogIblock, "SECTION_ID" => $compliteURL['ID'], "INCLUDE_SUBSECTIONS" => "Y"), array("PROPERTY_" . $prop['VALUE']));
                    $arRoundFiltered = [];
                    while ($arProps = $rsProps->Fetch()) {
                        if (empty($arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE']) || $arProps['CNT'] < 1) {
                            continue;
                        }

                        $propValue = $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'];
                        // Для свойств фильтра вида "ползунка"
                        if (!empty($prop['PROPERTY_TYPE'] == "N")) {
                            // Выборка значений 0.5, 1, 2 и т.д
                            if ($propValue < 1) {
                                $arRoundFiltered['0.5'] = $propValue;
                            } else {
                                $arRoundFiltered[floor($propValue)] = $propValue;
                            }
                        }
                        if ($prop['PROPERTY_TYPE'] == "E") {
                            $realValue = \CIBlockElement::GetList(array(), array("IBLOCK_ID" => $prop['SUB_IBLOCK'], "ID" => $propValue), false, false, ['NAME', 'CODE'])->GetNext();
                            $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'] = $realValue['NAME'];
                            if(!empty($realValue['CODE'])) {
                                $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'] = $realValue['CODE'];
                            }
                        } elseif ($prop['PROPERTY_TYPE'] == "G") {
                            $realValue = \CIBlockSection::GetByID($propValue)->GetNext();
                            $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'] = '.'.$realValue['NAME'];
                            if(!empty($realValue['CODE'])) {
                                $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'] = $realValue['CODE'];
                            }
                        } elseif ($prop['PROPERTY_TYPE'] == "L" && !empty($arProps['PROPERTY_' . $prop['VALUE'] . '_ENUM_ID'])) {
                            $arProps['XML_ID'] = \CIBlockPropertyEnum::GetByID($arProps['PROPERTY_' . $prop['VALUE'] . '_ENUM_ID'])['XML_ID'];
                            $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'] = \CIBlockPropertyEnum::GetByID($arProps['PROPERTY_' . $prop['VALUE'] . '_ENUM_ID'])['VALUE'];
                        }

                        $arProps['VALUE'] = $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'];
                        unset($arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE']);
                        $arProps['PROPERTY_TYPE'] = $prop['PROPERTY_TYPE'];
                        $arProps['CODE'] = $prop['VALUE'];

                        // Собираем массив для работы с ним
                        $arProperties[$kProp][] = $arProps;
                    }
                    /*-------КОНКРЕТНЫЕ ССЫЛКИ------*/
                } else {
                    $templateLinks = false;
                    /**
                     * [Для типа привязки позволяем искать по имени или по ID]
                     */
                    if ($prop['PROPERTY_TYPE'] == "E") {
                        $idPropList = \CIBlockElement::GetList(array(), array("IBLOCK_ID" => $prop['SUB_IBLOCK'], "NAME" => $prop['DESCRIPTION']), false, false, ['ID'])->GetNext();
                        $arFilter = array("IBLOCK_ID" => $catalogIblock, "SECTION_ID" => $compliteURL['ID'], "INCLUDE_SUBSECTIONS" => "Y", "PROPERTY_" . $prop['VALUE'] => $idPropList);
                    }
                    if ($prop['PROPERTY_TYPE'] != "E" || empty($idPropList)) {
                        $arFilter = array("IBLOCK_ID" => $catalogIblock, "SECTION_ID" => $compliteURL['ID'], "INCLUDE_SUBSECTIONS" => "Y", "PROPERTY_" . $prop['VALUE'].($prop['PROPERTY_TYPE'] == "L" ? "_VALUE" : "") => $prop['DESCRIPTION']);
                    }

                    $rsProps = \CIBlockElement::GetList(array(), $arFilter, array("PROPERTY_" . $prop['VALUE']));
                    $arRoundFiltered = [];
                    while ($arProps = $rsProps->Fetch()) {
                        // Если получаем пустое значение свойства или элементов меньше 1, то пропускаем
                        if (empty($arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE']) || $arProps['CNT'] < 1) {
                            continue;
                        }
                        $propValue = $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE'];
                        // Для свойств фильтра вида "ползунка"
                        if (!empty($prop['PROPERTY_TYPE'] == "N")) {
                            // Выборка значений 0.5, 1, 2 и т.д
                            if ($propValue < 1) {
                                $arRoundFiltered['0.5'] = $propValue;
                            } else {
                                $arRoundFiltered[floor($propValue)] = $propValue;
                            }
                        }

                        $arProps['VALUE'] = (!empty($idPropList) ? $prop['DESCRIPTION'] : $arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE']);
                        unset($arProps['PROPERTY_' . $prop['VALUE'] . '_VALUE']);
                        $arProps['PROPERTY_TYPE'] = $prop['PROPERTY_TYPE'];
                        $arProps['CODE'] = $prop['VALUE'];

                        if ($prop['PROPERTY_TYPE'] == "L" && !empty($arProps['PROPERTY_' . $prop['VALUE'] . '_ENUM_ID'])) {
                            $arProps['XML_ID'] = \CIBlockPropertyEnum::GetByID($arProps['PROPERTY_' . $prop['VALUE'] . '_ENUM_ID'])['XML_ID'];
                        }

                        $arProperties[$kProp][] = $arProps;
                    }
                }
            }


            // Если конечное кол-в свойств меньше чем изначально, то пропускаем
            // т.к мы работаем в режиме AND
            if (!empty($arProperties) && count($arProperties) == $count) {

                // Выборка уникальных значений
                $arUniqProps = self::uniqLinks($arProperties);
                // Сгенерированные ссылки проверяем на наличие элементов
                foreach ($arUniqProps as $keyUniq => $arProp) {
                    $arFilter = ["IBLOCK_ID" => $catalogIblock, "SECTION_ID" => $compliteURL['ID'], "INCLUDE_SUBSECTIONS" => "Y"];
                    foreach ($arProp as $key => $value) {
                        if ($value['PROPERTY_TYPE'] == 'E') {
                            $arFilter['PROPERTY_' . $value['CODE'] . '_VALUE'][] = $value['VALUE'];
                        } elseif ($value['PROPERTY_TYPE'] == 'G') {
                            $arFilter['PROPERTY_' . $value['CODE'] . '_VALUE'][] = $value['VALUE'];
                        } elseif ($value['PROPERTY_TYPE'] == 'L') {
                            $arFilter['PROPERTY_' . $value['CODE'] . '_ENUM_ID'][] = $value['PROPERTY_' . $value['CODE'] . '_ENUM_ID'];
                        } else {
                            $arFilter['PROPERTY_' . $value['CODE']][] = $value['VALUE'];
                        }
                    }

                    // Делаем запрос на получение элементов
                    $rsUniqProp = \CIBlockElement::GetList(array(), $arFilter, array());

                    if ($rsUniqProp == 0) {
                        unset($arUniqProps[$keyUniq]);
                    } else {
                        $link['OLD_LINK'] = $compliteURL['OLD_LINK'];
                        $link['NEW_LINK'] = $compliteURL['NEW_LINK'];

                        foreach ($arUniqProps[$keyUniq] as $key => $prop) {
                            $newFilterURL = mb_strtolower(\Cutil::translit($prop['VALUE'], "ru", $paramTranslitURL));
                            $oldFilterURL = mb_strtolower($prop['CODE'] . '-is-' . self::encodeUrl($prop['VALUE']));
                            // Для свойств фильтра вида "ползунка"
                            if (!empty($prop['PROPERTY_TYPE'] == "N")) {
                                // Ищем в массиве выборки данное значение, если не нашли пропускаем дальнейшие действия
                                if ($keyNewUrl = array_search($prop['VALUE'], $arRoundFiltered)) {
                                    // Т.к округление в меньшую сторону, а для 0.5 начинаем от 0
                                    $newFilterURL = $keyNewUrl;
                                    $oldFilterURL = mb_strtolower($prop['CODE'] . '-from-' . ($keyNewUrl < 0.999 ? 0 : $keyNewUrl) . '-to-' . $prop['VALUE']);
                                } else {
                                    continue;
                                }
                            }

                            if (!empty($prop['PROPERTY_TYPE'] == "L")) {
                                $oldFilterURL = mb_strtolower($prop['CODE'] . '-is-' . self::encodeUrl($prop['XML_ID']));
                            }

                            $tagName = parent::getPattern($tagNameVal, ['FILTER_VALUE' => (!empty($keyNewUrl) ? $keyNewUrl : $prop['VALUE'])]);
                            $link['NAME_TAG'] = $tagName;
                            $link['OLD_LINK'] = str_ireplace("#SMART_FILTER_PATH#", $oldFilterURL . "/#SMART_FILTER_PATH#", $link['OLD_LINK']);
                            $link['NEW_LINK'] = str_ireplace(["{FILTER_VALUE}", "{FILTER_CODE}"], [$newFilterURL, mb_strtolower($prop['CODE'])], $link['NEW_LINK']);

                            if ($templateLinks === true) {
                                $arLink[] = $link;
                            }else{
                                $arLink[count($arUniqProps[$keyUniq])] = $link;
                            }
                        }
                    }
                }
            }
        }

        if ($templateLinks === false) {
            $arLink = array_values($arLink);
        }

        foreach ($arLink as $key => $item) {
            // Удаляем остатки от генерации
            $arLink[$key]['OLD_LINK'] = str_replace("/#SMART_FILTER_PATH#", '', $item['OLD_LINK']);
            $arLink[$key]['NEW_LINK'] = str_replace("{FILTER_VALUE}", '', $item['NEW_LINK']);
        }
        // Событие перед записью ссылок
        $event = new \Bitrix\Main\Event("orwo.seosmartfilter", "OnBeforeLinkAdd", [$arLink]);
        $event->send();
        foreach ($event->getResults() as $eventResult) {
            if ($eventResult->getType() == \Bitrix\Main\EventResult::ERROR) { // если обработчик вернул ошибку, ничего не делаем
                continue;
            }
            $arLink = $eventResult->getParameters();
        }
        // Отдаем массив с сылками раздела на запись.
        self::setHighloadLinks($arLink, $arFields['ID']);

        if(!empty(parent::sitemapPath())) {
            $jsonSitemapsGet = parent::sitemapPath();
            $arSitemapsGet = json_decode($jsonSitemapsGet, true);
            \Orwo\SeoSmartFilter\Sitemap::get($arSitemapsGet);
        }
    }


    /**
     * [setHighloadLinks Запись ссылок в highloadblock]
     * @param array $arLink [description]
     */
    public function setHighloadLinks($arLink = [], $seoElemenID = '')
    {
        // Подключаем класс Highload блока
        $hldata   = \Bitrix\Highloadblock\HighloadBlockTable::getById(parent::moduleHighloadID())->fetch();
        $highloadClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hldata)->getDataClass();
        // При первом запросе запрашиваем ссылки данного элемента
        if (empty($arCreatedLinks) && !empty($seoElemenID)) {
            $arCreatedLinks = $highloadClass::getList(array('filter' => array('UF_ID' => $seoElemenID)))->fetchAll();
        }

        foreach ($arLink as $item) {
            $keyUpdate = '';
            $resultAddHL = [];
            
            // Если такая ссылка уже существует в другом условии
            if ($checkLink = parent::getLink($item['NEW_LINK'])) {
                if ($checkLink['UF_ID'] != $item['ID_CAT']) {
                    // Удаляем ссылку из другого источника
                    if ($checkLink['UF_NOT_UPDATE'] != 1) {
                        parent::delAllLinks($checkLink['ID']);
                    } else {
                        continue;
                    }
                }
            }


            // Проверяем что делать с сылками добвлять или обновлять.
            foreach ($arCreatedLinks as $kUpd => $vUpd) {
                if ($vUpd['UF_OLD'] == $item['OLD_LINK']) {
                    if ($vUpd['UF_NOT_UPDATE'] == 1) {
                        unset($item);
                        continue;
                    }
                    $keyUpdate = $kUpd;
                    $item['OLD_LINK'] = $arCreatedLinks[$keyUpdate]['UF_OLD'];
                    $item['NEW_LINK'] = $arCreatedLinks[$keyUpdate]['UF_NEW'];
                    $item['ID'] = $arCreatedLinks[$keyUpdate]['ID'];
                }
            }

            // Создаем ссылку
            if (empty($item)) {
                continue;
            }
            
            $resultAddHL = array(
                "UF_ACTIVE"   => 1,
                'UF_OLD'      => $item['OLD_LINK'],
                'UF_NEW'      => $item['NEW_LINK'],
                'UF_ID'       => $item['ID_CAT'],
                'UF_REDIRECT' => $item['REDIRECT'],
                'UF_SECTION'  => $item['SECTION_ID'],
                'UF_TAG'      => $item['NAME_TAG'],
                'ID' => ($item['ID'] ? $item['ID'] : '')
            );

            if (isset($arCreatedLinks[$keyUpdate]) && !empty($item['ID'])) {
                $highloadClass::update($item['ID'], $resultAddHL);
                unset($arCreatedLinks[$keyUpdate]);
            } else {
                unset($resultAddHL['ID']);
                $highloadClass::add($resultAddHL);
            }
        }
        // Возвращаем массив ссылок hl без тех которые обновили
        return $arCreatedLinks;
    }

    /**
     * [encodeUrl кодирование строки url по правилам bitrix]
     */
    public function encodeUrl($string)
    {
        $replace = ["/", ',', ' ', '.', '"'];
        $replacement = ["-", '%2C', '%20', '%2E', '%22'];
        $string = str_ireplace($replace, $replacement, $string);
        return $string;
    }

    /**
     * [uniqLinks Перебираем комбинации массивов]
     * @param  $arFields [array][Общий массив для перебора вариантов]
     * @return [array][Массив с комбинациями]
     */
    public function uniqLinks($arFields)
    {
        $count = count($arFields);
        for ($s = 0; $s < $count; $s++) {
            $i[$s] = 0;
            $n[$s] = count($arFields[$s]);
        }
        $arUniq = array();
        $done = false;
        do {
            $element = array();
            for ($s = 0; $s < $count; ++$s) {
                $element[] = $arFields[$s][$i[$s]];
            }
            $arUniq[] = $element;
            for ($s = $count - 1; $s >= 0; --$s) {
                $i[$s]++;
                if ($i[$s] >= $n[$s] && $s == 0) {
                    $done = true;
                }
                if ($i[$s] >= $n[$s]) {
                    $i[$s] = 0;
                } else {
                    break;
                }
            }
        } while (!$done);
        return $arUniq;
    }
}