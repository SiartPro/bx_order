<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentParameters = array(
    'GROUPS' => array(),
    'PARAMETERS' => array(
        'PATH_TO_BASKET' => array(
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('DK_ORDER_PATH_TO_BASKET'),
            'TYPE' => 'STRING'
        ),
        'PATH_TO_SUCCESS' => array(
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('DK_ORDER_PATH_TO_SUCCESS'),
            'TYPE' => 'STRING'
        ),
        'IMAGE_WIDTH' => array(
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('DK_IMAGE_WIDTH'),
            'TYPE' => 'STRING'
        ),
        'IMAGE_HEIGHT' => array(
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('DK_IMAGE_HEIGHT'),
            'TYPE' => 'STRING'
        ),
    ),
);