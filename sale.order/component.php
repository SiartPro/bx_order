<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */
/** @global CUser $USER */
/** @global CDatabase $DB */
/** @var CDkOrder $this */
/** @var string $componentPath */
/** @var string $componentName */
/** @var string $componentTemplate */
/** @var string $parentComponentPath */
/** @var string $parentComponentName */
/** @var string $parentComponentTemplate */

use Bitrix\Main\Loader;
use Project\StatusPoints;

global $APPLICATION;
global $USER;

Loader::includeModule('project.manage');

if ($arParams['IS_AJAX']) {
    $APPLICATION->RestartBuffer();
}

// создаём зказ, виртуальный или реальный
$this->createVirtualOrder();

$arResponse = array();
$arErrors = $this->getErrors();


if (isset($this->request['save']) && $this->request['save'] == 'Y' && empty($arErrors)) {
    StatusPoints::addPoints($this->getOrderId());
    LocalRedirect($arParams['PATH_TO_SUCCESS'] . '?ORDER_ID=' . $this->getOrderNumber());
} else {
    //$arResult = $this->getResult();
    $arResult['DELIVERY_LIST'] = $this->getOrderDeliveryList();
    $arResult['PAY_SYSTEM_LIST'] = $this->getOrderPaySystemList();
    $arResult['PROPS_LIST'] = $this->getOrderPropsList();
    $arResult['PERSON_TYPE_LIST'] = $this->getPersonTypes();
    $arResult['~DELIVERY_PRICE'] = $this->getDeliveryPrice();
    $arResult['DELIVERY_PRICE'] = $this->getDeliveryPriceFormat();
    $arResult['~ORDER_PRICE'] = $this->getOrderPrice();
    $arResult['ORDER_PRICE'] = $this->getOrderPriceFormat();
    $arResult['COUPON_DATA'] = $this->getCouponsData();
    $arResult['USER_DESCRIPTION'] = $this->getUserDescription();
    $arResult['ERRORS'] = $arErrors;
    $arResult['USER_DATA'] = array();
    if ($USER->IsAuthorized()) {
        $arResult['USER_DATA'] = $this->getUserData();
    }

    //$APPLICATION->AddViewContent('TOTAL_PRICE', $arResult['ORDER_PRICE']);
}

if ($this->arParams['IS_AJAX']) {
    ob_start();
}

$this->IncludeComponentTemplate();

if ($this->arParams['IS_AJAX']) {
    $arResponse['HTML'] = ob_get_contents();
    ob_end_clean();
    $arResponse['ERRORS'] = $this->errors;

    header('Content-Type: application/json');
    echo json_encode($arResponse);
    $APPLICATION->FinalActions();
    die();
}

