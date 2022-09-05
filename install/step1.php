<?
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if(!check_bitrix_sessid()) return;
//echo CAdminMessage::ShowNote("Модуль orwo.seosmartfilter(by orwo) установлен");
\Bitrix\Main\UI\Extension::load("ui.hint");
?>
<script>
BX.ready(function() {
    BX.UI.Hint.init(BX('my-container'));
})
</script>
<form action="<?echo $APPLICATION->GetCurPage();?>">
	<?echo bitrix_sessid_post(); ?>
	<input type="hidden" name="id" value="orwo.seosmartfilter">
	<input type="hidden" name="install" value="Y">
	<input type="hidden" name="step" value="2">
	<p> <?=Loc::getMessage("step_title")?> <span data-hint="<?=Loc::getMessage("step_ibloc_hint")?>"></span> </p>
	<select name="catalogIblockID" required >
	<?if (Loader::includeModule('iblock')) {?>
		<?php $resIblock = CIBlock::GetList([], array('ACTIVE'=>'Y'), false); ?>
		<?while ($arIblock = $resIblock->Fetch()) {?>
			<option value="<?=$arIblock['ID']?>"> <?=$arIblock['NAME']?> (ID: <?=$arIblock['ID']?>)</options>
		<?}?>
	<?}?>
	</select>
	<p> <?=Loc::getMessage("filter_template_title")?> <span data-hint="<?=Loc::getMessage("filter_template_hint")?>"></span> </p>
	<input type="text" size="100" name="filterSef" value="#SECTION_CODE_PATH#/filter/#SMART_FILTER_PATH#/apply/" placeholder="#SECTION_CODE_PATH#/filter/#SMART_FILTER_PATH#/apply/">
	<input type="submit" name="" value="<?=Loc::getMessage("submit_install"); ?>">
</form>