<?php
use Bitrix\Main\EventManager;

$eventManager = EventManager::getInstance();
$eventManager->addEventHandler("sale", "OnSaleBasketItemRefreshData", ["\SBTest\Handlers\SaleHandler", "setIgnoreEvents"],false,100);
$eventManager->addEventHandler("sale", "OnSaleBasketBeforeSaved", ["\SBTest\Handlers\SaleHandler", "distributeProductToStores"]);
