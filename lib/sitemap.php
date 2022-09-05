<?php
namespace Orwo\SeoSmartFilter;

use Bitrix\Main\Config\Option;

class Sitemap extends \Orwo\SeoSmartFilter\SetFilter
{
    public function get($arSitemapsReq = false)
    {
        $module_id = "orwo.seosmartfilter";
        Option::set($module_id, "sitemapGetStart", date("d.m.Y H:i:s"));
        // По дефолту путь к корню
        if ($arSitemapsReq == false) {
            $arSitemapsReq[] = array(
                'domain' => ($request->isHttps() == true ? "https://" : "http://").$_SERVER['HTTP_HOST'],
                'file' => $_SERVER['DOCUMENT_ROOT'].'/sitemap.xml'
            );
        }
        $request =  \Bitrix\Main\Context::getCurrent()->getRequest();
        // Получаем все ссылки
        $arResult = parent::getAllLinks();
        // И получаем оригинальную карту сайта.
        foreach ($arSitemapsReq as $arSitemap) {
            $originalSitemap = $arSitemap['file'];
            if (file_exists($originalSitemap)) {
                // Читаем xml object (Конвертация туда-сюда из json делает нормальный массив);
                $sitemap = json_decode(json_encode(simplexml_load_file($originalSitemap)), true);
                // Создаем элемент карты сайта
                $xml = new \SimpleXMLElement('<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9"/>');
                // Создаем массив с ссылками фильтра
                $arFilter = [];
                foreach ($arResult as $filterKey => $filterLink) {
                    $locCurPage = $arSitemap['domain'].$filterLink['UF_NEW'];
                    // Проверяем нет ли уже ссылки в карте сайта
                    if (array_search($locCurPage, array_column($sitemap['url'], 'loc')) === false) {
                        $arFilter[] = array(
                            'loc' => $locCurPage,
                            'lastmod' => date('c', time())
                        );
                    }
                }
                // Объединяем стандартные ссылки и ссылки фильтра
                $newSitemap = array_merge($sitemap['url'], $arFilter);
                foreach ($newSitemap as $key => $siteMapItem) {
                    // Создаем к каждому ключу обьект xml ссылки
                    $xmlUrl = $xml->addChild('url');
                    // Добавляем ключи в ссылку и меняем домен
                    foreach ($siteMapItem as $nameParam => $valueParam) {
                        $xmlUrl->addChild($nameParam, $valueParam);
                    }
                }
                // Переименовываем оригинальный файл и записываем новый
                rename($originalSitemap, $arSitemap['file']."._b_".date("dmy_his"));
                $newFile = fopen($arSitemap['file'], "w");
                if (fwrite($newFile, $xml->asXML())) {
                    $result = true;
                }
                fclose($newFile);
            }
        }
        return $result;
    }

    public function addFilterLinksToSitemap(){
        if(!empty(parent::sitemapPath())){
            $jsonSitemapsGet = parent::sitemapPath();
            $sitemapGetStart = parent::sitemapGetStart();
            $arSitemapsGet = json_decode($jsonSitemapsGet, true);
            foreach ($arSitemapsGet as $key => $arSitemap) {
                $originalSitemap = $arSitemap['file'];
                if (file_exists($originalSitemap)) {
                    $fileEditTime = date("d.m.Y H:i:s", filemtime($originalSitemap));
                    if($fileEditTime <= $sitemapGetStart) {
                        unset($arSitemapsGet[$key]);
                    }
                }
            }
            if(count($arSitemapsGet)){
                \Orwo\SeoSmartFilter\Sitemap::get($arSitemapsGet);
            }
        }
    }
}
