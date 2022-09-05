<?php
// check class
if (class_exists('\Orwo\SeoSmartFilter\SetFilter')) {
    // get section
    $arCorrectFilter = array();
    foreach ($arParams['FILTER']['ITEMS'] as $key => $prop) {
        if ($prop['USER_TYPE']=='directory'){
            continue;
        }
        foreach ($prop['VALUES'] as $key => $value) {
            if($prop['PROPERTY_TYPE']=='S'){
                $arCorrectFilter[mb_strtolower($prop['CODE'])][] = mb_strtolower(htmlspecialcharsBack(str_replace("/", "-", $value['VALUE'])));
            }
            if($prop['PROPERTY_TYPE']=='N'){
                $arCorrectFilter[mb_strtolower($prop['CODE'])][] = (float)$value['VALUE'];
            }
            if($prop['PROPERTY_TYPE']=='L'){
                $arCorrectFilter[mb_strtolower($prop['CODE'])][] = $value['URL_ID'];
            }
            if($prop['PROPERTY_TYPE']=='E' || $prop['PROPERTY_TYPE']=='G') {
                $arCorrectFilter[mb_strtolower($prop['CODE'])][] = mb_strtolower(str_replace("/", "-", $value['VALUE']));
                $arCorrectFilter[mb_strtolower($prop['CODE'])][] = $value['URL_ID'];
            }
        }
    }
    if(isset($arParams['SECTION_ID'])){
        $res = CIBlockSection::GetByID($arParams['SECTION_ID']);
    }else{
        if(isset($arParams['SECTION_CODE']) && isset($arParams['IBLOCK_ID']))
        $res = CIBlockSection::GetList(array(), array('IBLOCK_ID'=>$arParams['IBLOCK_ID'], 'CODE'=>$arParams['SECTION_CODE']));
    }
    if ($arRes = $res->GetNext()) {
        // section name
        $arParams['IBLOCK_SECTION'] = $arRes['NAME'];
        // $arParams URL
        $arResult['SECTION_PAGE_URL'] = $arRes['SECTION_PAGE_URL'];
        $arResult['SECTION_ID'] = $arRes['ID'];
        $arResult['IBLOCK_SECTION'] = $arRes['NAME'];
    }
    // get Highloadblock id
    $highloadID = \Orwo\SeoSmartFilter\SetFilter::moduleHighloadID();

    if (\Bitrix\Main\Loader::includeModule('highloadblock')) {
        $arHLBlock = Bitrix\Highloadblock\HighloadBlockTable::getById($highloadID)->fetch();
        $obEntity = Bitrix\Highloadblock\HighloadBlockTable::compileEntity($arHLBlock);
        $strEntityDataClass = $obEntity->getDataClass();

        $rsData = $strEntityDataClass::getList(array(
            'select' => array('UF_NEW', 'UF_OLD', 'UF_ID', 'UF_SECTION', 'UF_TAG', 'UF_TOP'),
            'filter' => array('UF_SECTION' => $arResult['SECTION_ID'])
        ));
        while ($arHighloadItem = $rsData->Fetch()) {
            $arItems[] = $arHighloadItem;
        }
    }

    // get iblock elements
    $arFilter = array(
        "IBLOCK_ID" => \Orwo\SeoSmartFilter\SetFilter::moduleIblockID(),
        "ACTIVE_DATE" => "Y",
        "ACTIVE" => "Y",
        "PROPERTY_SET_TAG_VALUE" => "Y",
        "PROPERTY_SET_ID_LIST" => $arResult['SECTION_ID']
    );
    $dbFilterPages = \CIBlockElement::GetList(array("sort" => "desc"), $arFilter, false, false, array("IBLOCK_ID", "ID", "NAME", 'PROPERTY_SECTION_TAG'));
    while ($obFilterPages = $dbFilterPages->fetch()) {
        if(!empty($obFilterPages['PROPERTY_SECTION_TAG_VALUE'])){
          $keySectionTag = Cutil::translit($obFilterPages['PROPERTY_SECTION_TAG_VALUE'], "ru", array("replace_space"=>"_","replace_other"=>"_", "change_case"=>"U"));
        }else{
          $keySectionTag = $obFilterPages['ID'];
        }

        if(!empty($obFilterPages['PROPERTY_SECTION_TAG_VALUE'])){
        $arResult['SECTIONS'][$keySectionTag]['NAME'] = $obFilterPages['PROPERTY_SECTION_TAG_VALUE'];
        }
        // get highload elements
        foreach ($arItems as  $arValue) {
            if ($arValue['UF_ID'] == $obFilterPages['ID']) {
                $arOldUrl = explode('/', $arValue['UF_OLD']);
                $arCorrectProps = array();
                foreach ($arOldUrl as $urlValue) {
                    if(strpos($urlValue, '-is-') !== false){
                        preg_match('/(.*)-is-(.*)/', $urlValue, $oldUrlM);
                        $arCorrectProps[$oldUrlM[1]][] = urldecode($oldUrlM[2]);
                    } elseif(strpos($urlValue, '-from-') !== false && strpos($urlValue, '-to-') !== false) {
                        preg_match('/(.*)-from-(.*)-to-(.*)/', $urlValue, $oldUrlM);
                        $arCorrectProps[$oldUrlM[1]][] = (float)$oldUrlM[3];
                    }
                }
                $createLinkFlag = true;
                foreach ($arCorrectProps as $code => $arPropValues) {
                    foreach ($arPropValues as $value) {
                        if(!in_array($value, $arCorrectFilter[$code])){
                            $createLinkFlag = false;
                        }
                    }
                }
                if($createLinkFlag){
                    if($arValue['UF_TOP'] == 1){
                      $arResult['SECTIONS'][$keySectionTag]['IN_TOP'] = 'Y';
                    }
                    $arResult['SECTIONS'][$keySectionTag]['ITEMS'][] =  array(
                      'NAME' => $arValue['UF_TAG'],
                      'LINK' => $arValue['UF_NEW'],
                      'OLD_LINK' => $arValue['UF_OLD'],
                      'TOP' => $arValue['UF_TOP']
                    );
                }
            }
        }
    }

    $this->IncludeComponentTemplate();
}
