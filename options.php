<?php
$module_id = "orwo.seosmartfilter";
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);
\Bitrix\Main\UI\Extension::load("ui.hint");
\Bitrix\Main\UI\Extension::load("ui.notification");
$moduleAccess = $APPLICATION->GetGroupRight($module_id);
if ($moduleAccess >= "W"):
    Loader::includeModule($module_id);
    IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/main/options.php");
    $aTabs = array(
        array("DIV" => "edit1", "TAB" => Loc::getMessage("tab_settings_1"), "ICON" => "", "TITLE" => Loc::getMessage("tab_settings_1")),
        array("DIV" => "edit2", "TAB" => Loc::getMessage("MAIN_TAB_RIGHTS"), "ICON" => "", "TITLE" => Loc::getMessage("MAIN_TAB_TITLE_RIGHTS"))
    );
    $tabControl = new CAdminTabControl("tabControl", $aTabs);

    /* [SAVE OPTIONS] */
    if ($_SERVER["REQUEST_METHOD"] == "POST" && $_REQUEST["Update"] != "" && check_bitrix_sessid()) {
        ob_start();
        require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php");
        ob_end_clean();
        if (!empty($_REQUEST['moduleIblockID'])) {
            Option::set($module_id, "moduleIblockID", $_REQUEST['moduleIblockID']);
        }
        if (!empty($_REQUEST['catalogIblockID'])) {
            Option::set($module_id, "catalogIblockID", $_REQUEST['catalogIblockID']);
        }
        if (!empty($_REQUEST['filterSef'])) {
            Option::set($module_id, "filterSef", $_REQUEST['filterSef']);
        }
        $arSitemaps = array();
        if (!empty($_REQUEST['domain']) && !empty($_REQUEST['file'])) {
        	foreach ($_REQUEST['domain'] as $key => $domain) {
        		if (!empty($domain) && !empty($_REQUEST['file'][$key])) {
        			$arSitemaps[] = array(
        				'domain' => $domain,
        				'file' => $_REQUEST['file'][$key]
        			);
        		}
        	}
        	$jsonSitemap = json_encode($arSitemaps);
        	Option::set($module_id, "filePath", $jsonSitemap);
        }

        if (strlen($_REQUEST["back_url_settings"]) > 0) {
            LocalRedirect($_REQUEST["back_url_settings"]);
        }
        LocalRedirect($APPLICATION->GetCurPage()."?mid=".urlencode($module_id)."&lang=".urlencode(LANGUAGE_ID)."&".$tabControl->ActiveTabParam());
    }

    /* [GENERATE SITEMAP] */
    if ($_SERVER["REQUEST_METHOD"] == "POST" && $_REQUEST["generate_sitemap"] != "" && check_bitrix_sessid()) {
        ob_start();
        require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php");
        ob_end_clean();
        print_r($_REQUEST);
        		$arSitemapsReq = array();
        		if (!empty($_REQUEST['domain']) && !empty($_REQUEST['file'])) {
        			foreach ($_REQUEST['domain'] as $key => $domain) {
        				if (!empty($domain) && !empty($_REQUEST['file'][$key])) {
        					$arSitemapsReq[] = array(
        						'domain' => $domain,
        						'file' => $_REQUEST['file'][$key]
        					);
        				}
        			}
        		} else {
        			$arSitemapsReq[] = array(
        				'domain' => ($request->isHttps() == true ? "https://" : "http://").$_SERVER['HTTP_HOST'],
        				'file' => $_SERVER['DOCUMENT_ROOT'].'/sitemap.xml'
        			);
        		}
        		$sitemap = \Orwo\SeoSmartFilter\Sitemap::get($arSitemapsReq);
        		if($sitemap == true){
        			LocalRedirect($APPLICATION->GetCurPage()."?mid=".urlencode($module_id)."&lang=".urlencode(LANGUAGE_ID)."&".$tabControl->ActiveTabParam().'&successSitemap=y');
        		} else {
        			LocalRedirect($APPLICATION->GetCurPage()."?mid=".urlencode($module_id)."&lang=".urlencode(LANGUAGE_ID)."&".$tabControl->ActiveTabParam().'&successSitemap=n');
        		}
    }
    Asset::getInstance()->addJs("/bitrix/js/main/core/core.js");
    $tabControl->Begin();
    ?>

	<form method="post" action="<?echo $APPLICATION->GetCurPage()?>?mid=<?=urlencode($module_id)?>&amp;lang=<?=LANGUAGE_ID?>">
	<?$tabControl->BeginNextTab();?>
	<?if($_REQUEST['successSitemap'] == 'y'){?>
	<script>
	BX.UI.Notification.Center.notify({
		content: "<?=Loc::getMessage("successSitemapY")?>",
		position: "top-center"
	});
	</script>
	<?}elseif($_REQUEST['successSitemap'] == 'n'){?>
		<script>
		BX.UI.Notification.Center.notify({
			content: "<?=Loc::getMessage("successSitemapN")?>",
			position: "top-center"
		});
		</script>
	<?}?>
	<script>
	BX.ready(function() {
	    BX.UI.Hint.init(BX('my-container'));
	})
	</script>
	<script>
		function settingsAddSitemap(a)
		{
			var form = BX.findParent(a, { 'className': 'adm-detail-content-item-block'});
			var sitemapRow = BX.findChild(form, { 'className': 'sitemap-input-row'}, true, true);
			var sitemapRowClone = sitemapRow[sitemapRow.length-1].cloneNode(true);
			var sitemapRowCloneInput = BX.findChild(sitemapRowClone, { 'tag': 'input'}, true, true);

			BX.adjust(sitemapRowCloneInput[sitemapRowCloneInput.length-1], {props: {value: ''}});
			BX.adjust(sitemapRowCloneInput[sitemapRowCloneInput.length-2], {props: {value: ''}});

			form.insertBefore(sitemapRowClone, a);
		}

		function settingsDeleteSitemap(a)
		{
			BX.remove(BX.findParent(a, {'className': 'sitemap-input-row'}));
			return false;
		}
	</script>
	<?/* [IBLOCK OPTIONS] */?>
	<span><?=Loc::getMessage("iblock_option")?></span>
	<span data-hint="<?=Loc::getMessage("iblock_option_hint")?>"></span>
	<?php $catalogIblockID = \Bitrix\Main\Config\Option::get($module_id, "catalogIblockID");?>
	<select name="catalogIblockID" required >
	<?if (Loader::includeModule('iblock')) {
        ?>
			<?php $resIblock = CIBlock::GetList([], array('ACTIVE'=>'Y'), false); ?>
				<?while ($arIblock = $resIblock->Fetch()) {
            if ($catalogIblockID == $arIblock['ID']) {
                ?>
            <option selected value="<?=$arIblock['ID']?>"> <?=$arIblock['NAME']?> (ID: <?=$arIblock['ID']?>)</options>
            <?php
            } else {
                ?>
						<option value="<?=$arIblock['ID']?>"> <?=$arIblock['NAME']?> (ID: <?=$arIblock['ID']?>)</options>
				<?php
            }
        } ?>
	<?php
    }?>
	</select>
	<br>
	<?/* [end IBLOCK OPTIONS] */?>

	<?/* [IBLOCK SEO OPTIONS] */?>
	<span><?=Loc::getMessage("iblock_seo_option")?></span>
	<span data-hint="<?=Loc::getMessage("iblock_seo_option_hint")?>"></span>
	<?php $moduleIblockID = \Bitrix\Main\Config\Option::get($module_id, "moduleIblockID");?>
	<select name="moduleIblockID" required >
	<?if (Loader::includeModule('iblock')) {
        ?>
			<?php $resIblock = CIBlock::GetList([], array('ACTIVE'=>'Y'), false); ?>
				<?while ($arIblock = $resIblock->Fetch()) {
            if ($moduleIblockID == $arIblock['ID']) {
                ?>
            <option selected value="<?=$arIblock['ID']?>"> <?=$arIblock['NAME']?> (ID: <?=$arIblock['ID']?>)</options>
            <?php
            } else {
                ?>
						<option value="<?=$arIblock['ID']?>"> <?=$arIblock['NAME']?> (ID: <?=$arIblock['ID']?>)</options>
				<?php
            }
        } ?>
	<?php
    }?>
	</select>
	<br>
	<?/* [end IBLOCK SEO OPTIONS] */?>

	<?/* [SEF OPTIONS] */?>
	<span><?=Loc::getMessage("filter_template_title")?></span>
	<span data-hint="<?=Loc::getMessage("filter_template_hint")?>"></span>
	<?php $filterSef = \Bitrix\Main\Config\Option::get($module_id, "filterSef");?>
	<input type="text" name="filterSef" size="70" value="<?=$filterSef?>">
	<br>
	<?/* [end SEF OPTIONS] */?>

	<?/* [SITEMAP GENERATE] */?>
	<?
	$jsonSitemapsGet = \Bitrix\Main\Config\Option::get($module_id, "filePath");
	$arSitemapsGet = json_decode($jsonSitemapsGet, true);
	?>
	<?$request =  \Bitrix\Main\Context::getCurrent()->getRequest();?>
	<?if (count($arSitemapsGet)):?>
		<?foreach ($arSitemapsGet as $key => $arSitemap):?>
			<div class="sitemap-input-row">
				<span><?=Loc::getMessage("domain_option")?></span>
				<span data-hint="<?=Loc::getMessage("domain_option_hint")?>"></span>
				<input type="text" name="domain[]" size="25" placeholder="<?=($request->isHttps() == true ? "https://" : "http://").$_SERVER['HTTP_HOST']?>" value="<?=$arSitemap['domain']?>">
				<span>&nbsp;</span>
				<span><?=Loc::getMessage("sitemap_path_option")?></span>
				<span data-hint="<?=Loc::getMessage("sitemap_path_option_hint")?>"></span>
				<?php $filePath = \Bitrix\Main\Config\Option::get($module_id, "filePath");?>
				<input type="text" name="file[]" size="70" placeholder="<?=$_SERVER['DOCUMENT_ROOT'].'/sitemap.xml'?>" value="<?=$arSitemap['file']?>">
				<a href="javascript:void(0)" onClick="settingsDeleteSitemap(this)"><img src="/bitrix/themes/.default/images/actions/delete_button.gif" border="0" width="20" height="20"></a>
			</div>
		<?endforeach;?>
	<?else:?>
		<div class="sitemap-input-row">
			<span><?=Loc::getMessage("domain_option")?></span>
			<span data-hint="<?=Loc::getMessage("domain_option_hint")?>"></span>
			<input type="text" name="domain[]" size="25" placeholder="<?=($request->isHttps() == true ? "https://" : "http://").$_SERVER['HTTP_HOST']?>" value="">
			<span>&nbsp;</span>
			<span><?=Loc::getMessage("sitemap_path_option")?></span>
			<span data-hint="<?=Loc::getMessage("sitemap_path_option_hint")?>"></span>
			<?php $filePath = \Bitrix\Main\Config\Option::get($module_id, "filePath");?>
			<input type="text" name="file[]" size="70" placeholder="<?=$_SERVER['DOCUMENT_ROOT'].'/sitemap.xml'?>" value="">
			<a href="javascript:void(0)" onClick="settingsDeleteSitemap(this)"><img src="/bitrix/themes/.default/images/actions/delete_button.gif" border="0" width="20" height="20"></a>
		</div>
	<?endif;?>
	<a href="javascript:void(0)" onclick="settingsAddSitemap(this)" hidefocus="true" class="adm-btn">Добавить домен и путь</a>
	<br>
	<br>
	<input type="submit" name="generate_sitemap" value="<?=Loc::getMessage("add_links_button")?>" title="<?=Loc::getMessage("add_links_button")?>" class="adm-btn-save">
	<span data-hint="<?=Loc::getMessage("add_links_button_hint")?>"></span>
	<?/* [end SITEMAP GENERATE] */?>

	<?$tabControl->BeginNextTab();?>
	<?require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php");?>

	<?$tabControl->Buttons();?>
		<input type="submit" name="Update" value="<?=Loc::getMessage("MAIN_SAVE")?>" title="<?=Loc::getMessage("MAIN_OPT_SAVE_TITLE")?>" class="adm-btn-save">
		<?=bitrix_sessid_post();?>
		<?if (strlen($_REQUEST["back_url_settings"]) > 0):?>
			<input type="button" name="Cancel" value="<?=Loc::getMessage("MAIN_OPT_CANCEL")?>" onclick="window.location='<?echo htmlspecialcharsbx(CUtil::addslashes($_REQUEST["back_url_settings"]))?>'">
			<input type="hidden" name="back_url_settings" value="<?=htmlspecialcharsbx($_REQUEST["back_url_settings"])?>">
		<?endif;?>
	<?$tabControl->End();?>
	</form>

<?endif;?>
