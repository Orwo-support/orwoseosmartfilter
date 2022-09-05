<?

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;

Class orwo_seosmartfilter extends CModule
{
    var $MODULE_ID = "orwo.seosmartfilter";
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;
    var $MODULE_GROUP_RIGHTS = "N";

    function orwo_seosmartfilter(){
        $arModuleVersion = array();

        $path = str_replace("\\", "/", __FILE__);
        $path = substr($path, 0, strlen($path) - strlen("/index.php"));

        $this->MODULE_ID = Loc::getMessage("module_id");

        include($path."/version.php");
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];

        $this->MODULE_NAME = Loc::getMessage("module_name");
        $this->MODULE_DESCRIPTION = Loc::getMessage("module_desc");
    }

    function DoInstall()
    {
        global $APPLICATION, $step, $catalogIblockID, $filterSef;;
        $step = IntVal($step);
        if (!IsModuleInstalled("orwo.seosmartfilter")){
            if ($step != 2) {
                $APPLICATION->IncludeAdminFile(Loc::getMessage("step_title"),
                    $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/orwo.seosmartfilter/install/step1.php");
            }else{
                $this->InstallFiles();
                $installSeoIblock = $this->IblockCreate($catalogIblockID);

                ModuleManager::registerModule($this->MODULE_ID);
                // set options
                Option::set($this->MODULE_ID, "moduleIblockID", $installSeoIblock['moduleIblockID']);
                Option::set($this->MODULE_ID, "catalogIblockID", $catalogIblockID);
                Option::set($this->MODULE_ID, "filterSef", $filterSef);
                $sitemapPath = $_SERVER['DOCUMENT_ROOT'].'/sitemap-iblock-'.$catalogIblockID.'.xml';
                $arSitemaps = array();
                if (file_exists($sitemapPath)) {
                    $request =  \Bitrix\Main\Context::getCurrent()->getRequest();
                    $arSitemaps[] = array(
                        'domain' => ($request->isHttps() == true ? "https://" : "http://").$_SERVER['HTTP_HOST'],
                        'file' => $sitemapPath
                    );
                }
                $jsonSitemap = json_encode($arSitemaps);
                Option::set($this->MODULE_ID, "filePath", $jsonSitemap);
                Option::set($this->MODULE_ID, "sitemapGetStart", date("d.m.Y H:i:s"));
                // edit form settings
                $this->IblockSettings($installSeoIblock['moduleIblockID']);

                $Installhighload = $this->highloadCreate();

                $this->agentCreate();

                $eventManager = \Bitrix\Main\EventManager::getInstance();
                // Подмена URL
                $eventManager->registerEventHandler("main", "OnPageStart", $this->MODULE_ID, "\Orwo\SeoSmartFilter\SetFilter", "searchRewrite");
                // Часть действий сбрасываем на обработчик обновления/добавления
                $eventManager->registerEventHandler("iblock", "OnBeforeIBlockElementUpdate", $this->MODULE_ID, "\Orwo\SeoSmartFilter\AddLinks", "beforetUpdateElement");
                $eventManager->registerEventHandler("iblock", "OnAfterIBlockElementAdd", $this->MODULE_ID, "\Orwo\SeoSmartFilter\AddLinks", "beforetUpdateElement");
                // Подмена Мета-тегов
                $eventManager->registerEventHandler("main", "OnEpilog", $this->MODULE_ID, '\Orwo\SeoSmartFilter\SetFilter', "addMeta", 10);
                // Дабавляем на панель администрирования кнопки
                $eventManager->registerEventHandler("main", "OnBeforeProlog", $this->MODULE_ID, '\Orwo\SeoSmartFilter\EventHelper', "panelButton");
                // Вкладка "Документация" и "ЧПУ"
                $eventManager->registerEventHandler("main", "OnAdminIBlockElementEdit", $this->MODULE_ID, '\Orwo\SeoSmartFilter\EventHelper', "onInitHelpTab", 10);
                // Иконка инфоблока
                $eventManager->registerEventHandler('main', 'OnBuildGlobalMenu', $this->MODULE_ID, '\Orwo\SeoSmartFilter\EventHelper', 'iconSeoIblock');
                // Кастомное свойство
                $eventManager->registerEventHandler('iblock', 'OnIBlockPropertyBuildList', $this->MODULE_ID, '\Orwo\SeoSmartFilter\EventHelper', 'selectPropType');

                $APPLICATION->IncludeAdminFile(Loc::getMessage("FORM_INSTALL_TITLE"),
                    $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/orwo.seosmartfilter/install/step2.php");
            }
            // ModuleManager::registerModule("orwo.seosmartfilter");
            // $APPLICATION->IncludeAdminFile(Loc::getMessage("FORM_INSTALL_TITLE"),
            //     $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/orwo.seosmartfilter/install/step.php");
        }
        
    }

    function DoUninstall()
    {
        global $APPLICATION;
        if (Loader::includeModule('iblock')) {
            $rsTypeIBlock = new CIBlockType;
            $rsTypeIBlock::Delete("orwo_seosmartfilter");
            $highloadID = \Bitrix\Main\Config\Option::get($this->MODULE_ID, "highloadID");
            if (\Bitrix\Main\Loader::includeModule("highloadblock")) {
                $deleteHighloadBlock = \Bitrix\Highloadblock\HighloadBlockTable::delete($highloadID);
            }

            ModuleManager::unRegisterModule($this->MODULE_ID);

            $APPLICATION->IncludeAdminFile(Loc::getMessage("FORM_INSTALL_TITLE"), 
                $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/orwo.seosmartfilter/install/unstep.php");
        }
        
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . "/components", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/components", true, true);
        return true;
    }

    public function IblockCreate($catalogIblockID)
    {
        $rsSites = \Bitrix\Main\SiteTable::getList()->fetchAll();
        foreach ($rsSites as $key => $value) {
            $arSiteId[] = $value['LID'];
        }
        if (Loader::includeModule('iblock')) {
            $iblocktype = "orwo_seosmartfilter";
            // create iblock type
            $rsTypeIBlock = new CIBlockType;
            $arFields = array(
                "ID"        => $iblocktype,
                "SECTIONS"  => "Y",
                "SORT"      => 1,
                "LANG"      => ["ru" => ["NAME" => Loc::getMessage('iblock_type_name')]]
            );
            // create iblock
            if ($rsTypeIBlock->Add($arFields)) {
                $rsIBlock = new CIBlock;
                $arFields = array(
                    "NAME"           => Loc::getMessage('iblock_name'),
                    "ACTIVE"         => "Y",
                    "IBLOCK_TYPE_ID" => $iblocktype,
                    "SITE_ID"        => $arSiteId
                );
                // set props
                if ($iblockID = $rsIBlock->Add($arFields)) {
                    $propFilter = array(
                        "SORT" => 100,
                        "NAME" => Loc::getMessage('prop_filter_name'),
                        "CODE" => "PROP_FILTER",
                        "HINT" => Loc::getMessage('prop_filter_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "S",
                        "WITH_DESCRIPTION" => "Y",
                        "USER_TYPE" => 'selectPropType',
                        "MULTIPLE" => Y,
                        "MULTIPLE_CNT" => 1,
                        "IBLOCK_ID" => $iblockID,
                    );
                    $propIdList = array(
                        "SORT" => 200,
                        "NAME" => Loc::getMessage('prop_id_list_name'),
                        "CODE" => "SET_ID_LIST",
                        "HINT" => Loc::getMessage('prop_id_list_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "G",
                        "IBLOCK_ID" => $iblockID,
                        "LINK_IBLOCK_ID" => $catalogIblockID,
                        "MULTIPLE" => Y,
                        "MULTIPLE_CNT" => 10,
                        "COL_COUNT" => 30,
                    );
                    $propRedirect = array(
                        "SORT" => 300,
                        "NAME" => Loc::getMessage('prop_redirect_name'),
                        "CODE" => "REDIRECT",
                        "HINT" => Loc::getMessage('prop_redirect_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "L",
                        "LIST_TYPE" => "C",
                        "IBLOCK_ID" => $iblockID,
                        "VALUES" => array(array(
                            "VALUE" => "Y",
                            "DEF" => "N",
                        ))
                    );
                    $propSEF = array(
                        "SORT" => 400,
                        "NAME" => Loc::getMessage('prop_sef_name'),
                        "CODE" => "NEW_SEF",
                        "HINT" => Loc::getMessage('prop_sef_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "S",
                        "IBLOCK_ID" => $iblockID,
                        "DEFAULT_VALUE" => '/filter/{FILTER_VALUE}/'
                    );
                    $propSetTag = array(
                        "SORT" => 500,
                        "NAME" => Loc::getMessage('prop_add_tag_name'),
                        "CODE" => "SET_TAG",
                        "HINT" => Loc::getMessage('prop_add_tag_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "L",
                        "LIST_TYPE" => "C",
                        "IBLOCK_ID" => $iblockID,
                        "VALUES" => array(array(
                            "VALUE" => "Y",
                            "DEF" => "Y",
                        ))
                    );
                    $propNameTag = array(
                        "SORT" => 600,
                        "NAME" => Loc::getMessage('prop_name_tag_name'),
                        "CODE" => "NAME_TAG",
                        "HINT" => Loc::getMessage('prop_name_tag_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "S",
                        "IBLOCK_ID" => $iblockID,
                        "DEFAULT_VALUE" => '{FILTER_VALUE}'
                    );
                    $propSectionTag = array(
                        "SORT" => 700,
                        "NAME" => Loc::getMessage('prop_section_tag_name'),
                        "CODE" => "SECTION_TAG",
                        "HINT" => Loc::getMessage('prop_section_tag_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "S",
                        "IBLOCK_ID" => $iblockID
                    );
                    $propReplacePropValue = array(
                        "SORT" => 800,
                        "NAME" => Loc::getMessage('prop_replace_prop_value_name'),
                        "CODE" => "REPLACE_PROP_VALUE",
                        "HINT" => Loc::getMessage('prop_replace_prop_value_hint'),
                        "ACTIVE" => "Y",
                        "PROPERTY_TYPE" => "S",
                        "WITH_DESCRIPTION" => "Y",
                        "MULTIPLE" => Y,
                        "MULTIPLE_CNT" => 1,
                        "IBLOCK_ID" => $iblockID
                    );

                    $rsIBlockProperty = new CIBlockProperty;
                    $rsIBlockProperty->Add($propFilter);
                    $rsIBlockProperty->Add($propIdList);
                    $rsIBlockProperty->Add($propRedirect);
                    $rsIBlockProperty->Add($propSEF);
                    $rsIBlockProperty->Add($propSetTag);
                    $rsIBlockProperty->Add($propNameTag);
                    $rsIBlockProperty->Add($propSectionTag);
                    $rsIBlockProperty->Add($propReplacePropValue);

                    $result['moduleIblockID'] = $iblockID;
                    return $result;
                }
            }
        }
    }

    public function IblockSettings($IBLOCK_ID)
    {
        // Вкладки и свойства
        $arFormSettings = array(
            array(
                array("edit1", "Создания правила"), // Название вкладки
                array("NAME", "*Имя правила"), // Свойство со звездочкой - помечается как обязательное
                array("ACTIVE", "Активность"),
                array("SORT", "Сортировка"),
                array("empty", "Настройка ЧПУ"),
            ),
        );

        // Закидываем свойства
        $rsProperty = CIBlockProperty::GetList(['sort' => 'asc'], ['IBLOCK_ID' => $IBLOCK_ID]);
        while ($prop = $rsProperty->Fetch()) {
            $arFormSettings[0][] = array('PROPERTY_' . $prop['ID'], $prop['NAME']);
            if ($prop['CODE'] == 'NEW_SEF') {
                $arFormSettings[0][] = array("empty2", "Настройка тегов");
            }
        }
        $arFormSettings[0][] = array("empty3", "Настройка мета-тегов");
        // Сео вкладку перекидываем сюда
        $arFormSettings[0][] = array("IPROPERTY_TEMPLATES_ELEMENT_META_TITLE", "Шаблон META TITLE");
        $arFormSettings[0][] = array("IPROPERTY_TEMPLATES_ELEMENT_META_DESCRIPTION", "Шаблон META DESCRIPTION");
        $arFormSettings[0][] = array("IPROPERTY_TEMPLATES_ELEMENT_PAGE_TITLE", "Заголовок элемента");

        // Сериализация
        $arFormFields = array();
        foreach ($arFormSettings as $key => $arFormFields) {
            $arFormItems = array();
            foreach ($arFormFields as $strFormItem) {
                $arFormItems[] = implode('--#--', $strFormItem);
            }
            $arStrFields[] = implode('--,--', $arFormItems);
        }
        $arSettings = array("tabs" => implode('--;--', $arStrFields));
        // Применяем настройки для всех пользователей для данного инфоблока
        $rez = CUserOptions::SetOption("form", "form_element_" . $IBLOCK_ID, $arSettings, $bCommon = true, $userId = false);
    }

    public function highloadCreate()
    {
        if (\Bitrix\Main\Loader::includeModule("highloadblock")) {
            $highloadTableName = 'seofilterlinks';
            $addHighloadBlock = \Bitrix\Highloadblock\HighloadBlockTable::add(array(
                'NAME' => 'SeoFilterLinks',
                'TABLE_NAME' => $highloadTableName
            ));
            if ($addHighloadBlock) {
                $highloadID = $addHighloadBlock->getId();
                // Записываем в настройки HL блок
                Option::set($this->MODULE_ID, "highloadID", $highloadID);
                Option::set($this->MODULE_ID, "highloadTableName", $highloadTableName);

                $highloadUfNew = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_NEW',
                    'USER_TYPE_ID'      => 'string',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Новый URL',
                        'en'    => 'URL',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Новый URL',
                        'en'    => 'URL',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Новый URL',
                        'en'    => 'URL',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                    'SETTINGS' => ['SIZE' => '60']
                );

                $highloadUfOld = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_OLD',
                    'USER_TYPE_ID'      => 'string',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Оригинальный URL',
                        'en'    => 'URL',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Оригинальный URL',
                        'en'    => 'URL',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Оригинальный URL',
                        'en'    => 'URL',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                    'SETTINGS' => ['SIZE' => '60']
                );

                $highloadUfRedirect = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_REDIRECT',
                    'USER_TYPE_ID'      => 'boolean',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Редирект на новую ссылку',
                        'en'    => 'REDIRECT',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Редирект на новую ссылку',
                        'en'    => 'REDIRECT',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Редирект на новую ссылку',
                        'en'    => 'REDIRECT',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );

                $highloadUfId = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_ID',
                    'USER_TYPE_ID'      => 'integer',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'ID SEO элемента',
                        'en'    => 'ID',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'ID SEO элемента',
                        'en'    => 'ID',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'ID SEO элемента',
                        'en'    => 'ID',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );

                $highloadUfActive = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_ACTIVE',
                    'USER_TYPE_ID'      => 'boolean',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => true,
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Активность',
                        'en'    => 'Active',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Активность',
                        'en'    => 'Active',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Активность',
                        'en'    => 'Active',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );
                $highloadUfSection = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_SECTION',
                    'USER_TYPE_ID'      => 'string',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'ID раздела',
                        'en'    => 'URL',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'ID раздела',
                        'en'    => 'URL',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'ID раздела',
                        'en'    => 'URL',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );

                $highloadUfTag = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_TAG',
                    'USER_TYPE_ID'      => 'string',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => '',
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Имя тега',
                        'en'    => 'URL',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Имя тега',
                        'en'    => 'URL',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Имя тега',
                        'en'    => 'URL',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );

                $highloadUfNotUpdate = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_NOT_UPDATE',
                    'USER_TYPE_ID'      => 'boolean',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => false,
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Не перезаписывать',
                        'en'    => 'Not update',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Не перезаписывать',
                        'en'    => 'Not update',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Не перезаписывать',
                        'en'    => 'Not update',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => 'Ссылка не будет перезаписана при обновлении элемента условий',
                        'en'    => '',
                    ),
                );
                $highloadUfTop = array(
                    'ENTITY_ID'         => 'HLBLOCK_' . $highloadID,
                    'FIELD_NAME'        => 'UF_TOP',
                    'USER_TYPE_ID'      => 'boolean',
                    'XML_ID'            => '',
                    'SORT'              => 500,
                    'MULTIPLE'          => 'N',
                    'MANDATORY'         => 'Y',
                    'SHOW_FILTER'       => 'N',
                    'SHOW_IN_LIST'      => '',
                    'EDIT_IN_LIST'      => '',
                    'IS_SEARCHABLE'     => 'N',
                    'SETTINGS'          => array(
                        'DEFAULT_VALUE' => false,
                    ),
                    'EDIT_FORM_LABEL'   => array(
                        'ru'    => 'Популярный',
                        'en'    => 'Popular',
                    ),
                    'LIST_COLUMN_LABEL' => array(
                        'ru'    => 'Популярный',
                        'en'    => 'Popular',
                    ),
                    'LIST_FILTER_LABEL' => array(
                        'ru'    => 'Популярный',
                        'en'    => 'Popular',
                    ),
                    'ERROR_MESSAGE'     => array(
                        'ru'    => 'err',
                        'en'    => 'err',
                    ),
                    'HELP_MESSAGE'      => array(
                        'ru'    => '',
                        'en'    => '',
                    ),
                );


                $oUserTypeEntity  = new \CUserTypeEntity();
                $userTypeId = $oUserTypeEntity->Add($highloadUfNew);
                $userTypeId = $oUserTypeEntity->Add($highloadUfOld);
                $userTypeId = $oUserTypeEntity->Add($highloadUfRedirect);
                $userTypeId = $oUserTypeEntity->Add($highloadUfId);
                $userTypeId = $oUserTypeEntity->Add($highloadUfActive);
                $userTypeId = $oUserTypeEntity->Add($highloadUfSection);
                $userTypeId = $oUserTypeEntity->Add($highloadUfTag);
                $userTypeId = $oUserTypeEntity->Add($highloadUfNotUpdate);
                $userTypeId = $oUserTypeEntity->Add($highloadUfTop);
            }
        }
    }

    public function agentCreate()
    {
        CAgent::AddAgent(
            "\Orwo\SeoSmartFilter\Sitemap::addFilterLinksToSitemap();",
            "orwo.seosmartfilter",
            "N",
            300,
            "",
            "N"
        );
    }
}
?>