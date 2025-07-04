<?php

namespace SBTest\Handlers;
use Bitrix\Main\Context;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Fuser;
Loader::includeModule('sotbit.multibasket');

use Bitrix\Bizproc\Activity\Condition;
use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Engine\CurrentUser;
use Sotbit\Multibasket\DeletedFuser;
use Sotbit\Multibasket\Entity\EO_MBasket;
use Sotbit\Multibasket\Models\MBasketItem;
use Sotbit\Multibasket\Entity\MBasketTable;
use Sotbit\Multibasket\Entity\MBasketItemTable;
use Sotbit\Multibasket\Entity\MBasketItemPropsTable;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Order;
use Sotbit\Multibasket\DTO\BasketItemDTO;
use Sotbit\Multibasket\DTO\CurrentBasketDTO;
use Sotbit\Multibasket\DTO\ViewSettingsDTO;
use Sotbit\Multibasket\Models\MBasket;
use Sotbit\Multibasket\Models\MBasketCollection;

class SaleHandler {

    private static $isBasketUpdated = false;

    // Get store id by product
    private static function getStoreByProductId($productId) : int {

        $productStoreId = 0;

        // If product is SKU, replace its ID with the parent product ID
        $propertyIterator = \CIBlockElement::GetProperty(
                SB_TEST_IBLOCK_CATALOG_SKU_ID,
                $productId,
                [],
                ['CODE' => 'CML2_LINK']
        );

        if ($productProps = $propertyIterator->Fetch()) {
          
            if($productProps['VALUE'] > 0){
                $productId = $productProps['VALUE'];
            }

        }
        
        // Get current product`s store id.
        $propertyIterator = \CIBlockElement::GetProperty(
                SB_TEST_IBLOCK_CATALOG_ID,
                $productId,
                [],
                ['CODE' => SB_TEST_STORE_ID_PROP_CODE]
        );

        if ($productProps = $propertyIterator->Fetch()) {
        
            if($productProps['VALUE'] > 0){
                $productStoreId = $productProps['VALUE'];
            }
           
        }

        return $productStoreId;

    }


    // Prevent execution event from checkstorelistener.php
    // Store`s quantity test in 'distributeProductToStores' method.
    public static function setIgnoreEvents() {
                  
        $ref = new \ReflectionClass(\Sotbit\Multibasket\Models\MBasketCollection::class);
        $prop = $ref->getProperty('basketEventIgnore');
        $prop->setAccessible(true);
        $prop->setValue(null, true); 
        
        return true;
    }

    // Automate distiribute product to its store
    // If current product`s store doesn't have necessary quantity
    // check on default store
    // otherwise throw default error.

    public static function distributeProductToStores(\Bitrix\Main\Event $event) {

        // Do not procceed unless sotbit.multibasket module installed
        if (!Loader::includeModule('sotbit.multibasket') && !Loader::includeModule('iblock')) {
            return true;
        }
        
        $entity = $event->getParameter("ENTITY");
    
        $basketItems = $entity->getBasketItems();

        foreach ($basketItems as $basketItem) {
            $productId = (int)$basketItem->getProductId();
            $productQty = (int)$basketItem->getQuantity();
        }

        $productStoreId = self::getStoreByProductId($productId);

        // print PHP_EOL . '[SH] $productId = ' . $productId . PHP_EOL;
        // print PHP_EOL . '[SH] $productQty = ' . $productQty . PHP_EOL;

        $context = Context::getCurrent();

        $fuser = new Fuser;
        $mBasketTable = new MBasketTable;
        $mBasketItemTable = new MBasketItemTable;
        $mBasketItemPropsTable = new MBasketItemPropsTable;

        $mBasket = MBasket::getCurrent(
            $fuser,
            $mBasketTable,
            $mBasketItemTable,
            $mBasketItemPropsTable,
            $context
        );
        
        // Get initial multibasket store ID
        $mBasketInitialStoreId = $mBasket->getStoreId();
        
        $mStoreProduct = StoreProductTable::getList([
            'filter' => [
                'PRODUCT_ID' => $productId,
                'STORE_ID' => $mBasketInitialStoreId,
            ],
            'select' => ['AMOUNT']
        ])->fetch();


        // print '[SH] Current multibasket store id: ' . $mBasketInitialStoreId . PHP_EOL;
        // print PHP_EOL;
        // print_r($mStoreProduct);
        // print PHP_EOL;       

        // Test if current multibasket store has 0 or less requested product`s quantity 
        if($mStoreProduct['AMOUNT'] <= $productQty) {
        
            $pStoreProduct = StoreProductTable::getList([
                'filter' => [
                    'PRODUCT_ID' => $productId,
                    'STORE_ID' => $productStoreId,
                ],
                'select' => ['AMOUNT']
            ])->fetch();

            // print_r('//// Current prop store: ' . $productStoreId .PHP_EOL);
            // print PHP_EOL;
            // print_r($pStoreProduct);
            // print PHP_EOL;

            // Test if product`s store  has requested product`s quantity 
            // If product`s store has requested quantiry, then temporary set multibasket 
            if($pStoreProduct['AMOUNT'] >= $productQty) {
                
                // print 'before change store ID :' . $mBasket->getStoreId() . PHP_EOL; 
             
                $mbasketCollection = MBasketCollection::getObject($fuser, new MBasketTable, $context);

                $basketForStore = null;
                
                foreach ($mbasketCollection->getAll() as $basket) {
                    if ($basket->getStoreId() == $productStoreId) {
                        $basketForStore = $basket;
                        break;
                    }
                }

                if($basketForStore) {
                    $basketDTOToSetCurrent = new \Sotbit\Multibasket\DTO\BasketDTO([
                        'ID' => $basketForStore->getId(),
                        'CURRENT_BASKET' => true,
                    ]);
                    
                    // Fire update once.
                    if(!self::$isBasketUpdated){
                        self::$isBasketUpdated = true;
                        $mbasketCollection->updateBasket($basketDTOToSetCurrent);
                    }
                }

            }
            // Throw default error when product`s quantity less than product`s store or current multibasket store.
            else {

                print '[SH] Current multibasket store id: ' . $mBasketInitialStoreId . PHP_EOL;
                print PHP_EOL;
                print_r($mStoreProduct);
                print PHP_EOL;

                print_r('[SH] Current product store id: ' . $productStoreId .PHP_EOL);
                print PHP_EOL;
                print_r($pStoreProduct);
                print PHP_EOL;
                
                print_r('[SH] raise error' .PHP_EOL);

                return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::ERROR,
                    new \Bitrix\Sale\ResultError('Этот товар нельзя добавить в корзину. <br / > Товара нет на складе.', 
                    []));

                return false;
            }

        }
        // Procced default behaviour if current multibasket store has requested product`s quantity
        else {
            return true;    
        }

        return true;

    }
}