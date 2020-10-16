<?
/**
 * Created by PhpStorm.
 * @author Karikh Dmitriy <demoriz@gmail.com>
 * @date 20.07.2020
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Sale\Order;
use Bitrix\Sale\Fuser;
use Bitrix\Sale\Basket;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Sale\Shipment;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\SystemException;
use Bitrix\Catalog\Product\Price;
use Bitrix\Sale\DiscountCouponsManager;

/**
 * Class CSiartOrder
 */
class CSiartOrder extends CBitrixComponent
{
    /**
     * @var Order $order
     * @var array $errors
     * @var array $arDeliveryServiceAll
     */
    protected $order;
    protected $errors = array();
    protected $arDeliveryServiceAll = array();

    /**
     * CSiartOrder constructor.
     * @param null $component
     * @throws \Bitrix\Main\LoaderException
     */
    public function __construct($component = null)
    {
        parent::__construct($component);

        if (!Loader::includeModule('iblock')) {
            $this->errors[] = 'No iblock module';
        };

        if (!Loader::includeModule('sale')) {
            $this->errors[] = 'No sale module';
        };

        if (!Loader::includeModule('catalog')) {
            $this->errors[] = 'No catalog module';
        };

        if (!Loader::includeModule('currency')) {
            $this->errors[] = 'No currency module';
        };

        if (!Loader::includeModule('subscribe')) {
            $this->errors[] = 'No subscribe module';
        };
    }

    /**
     * Проверка и перепределение входящих параметров.
     *
     * @param $arParams
     * @return array
     */
    public function onPrepareComponentParams($arParams)
    {
        // Тип плательщика
        if (isset($arParams['PERSON_TYPE_ID']) && (int)$arParams['PERSON_TYPE_ID'] > 0) {
            $arParams['PERSON_TYPE_ID'] = (int)$arParams['PERSON_TYPE_ID'];

        } else {
            if ((int)$this->request['PERSON_TYPE_ID'] > 0) {
                $arParams['PERSON_TYPE_ID'] = (int)$this->request['PERSON_TYPE_ID'];
            } else {
                $arParams['PERSON_TYPE_ID'] = 1;
            }
        }

        // Ширина и высота изображения в корзине
        $arParams['IMAGE_WIDTH'] = (int)$arParams['IMAGE_WIDTH'];
        $arParams['IMAGE_HEIGHT'] = (int)$arParams['IMAGE_HEIGHT'];
        if ($arParams['IMAGE_WIDTH'] == 0) {
            $arParams['IMAGE_WIDTH'] = 999999999;
        }
        if ($arParams['IMAGE_HEIGHT'] == 0) {
            $arParams['IMAGE_HEIGHT'] = 999999999;
        }

        // Поле логина для нового пользователя
        if (empty($arParams['NEW_USER_LOGIN_FIELD'])) {
            $arParams['NEW_USER_LOGIN_FIELD'] = 'EMAIL';
        }

        // Служба доставки по умолчанию
        if (isset($arParams['DELIVERY_ID']) && (int)$arParams['DELIVERY_ID'] > 0) {
            $arParams['DELIVERY_ID'] = (int)$arParams['DELIVERY_ID'];

        } else {
            if ((int)$this->request['DELIVERY_ID'] > 0) {
                $arParams['DELIVERY_ID'] = (int)$this->request['DELIVERY_ID'];

            } else {
                $arParams['DELIVERY_ID'] = 0;
            }
        }

        // Платёжная система по умолчанию
        if (isset($arParams['PAYMENT_ID']) && (int)$arParams['PAYMENT_ID'] > 0) {
            $arParams['PAYMENT_ID'] = (int)$arParams['PAYMENT_ID'];

        } else {
            if ((int)$this->request['PAYMENT_ID'] > 0) {
                $arParams['PAYMENT_ID'] = (int)$this->request['PAYMENT_ID'];

            } else {
                $arParams['PAYMENT_ID'] = 0;
            }
        }

        // Использовать ли номер заказа вместо ID?
        $arParams['USE_ORDER_NUMBER'] = ($arParams['USE_ORDER_NUMBER'] == 'Y');

        return $arParams;
    }

    /**
     * Основной метод компонента.
     * Точка входа.
     *
     * @return mixed|void|null
     */
    public function executeComponent()
    {
        global $APPLICATION;

        $this->createVirtualOrder();
        $this->arResult['ERRORS'] = $this->errors;
        $this->arResult['HIDDEN'] = md5(__CLASS__);


        // если была команда на сохранение заказа
        if ($this->request['MODE'] == $this->arResult['HIDDEN'] && $this->request['SAVE'] == 'Y' && empty($this->arResult['ERRORS'])) {
            // если запрос был AJAX, отдаём JSON
            if ($this->request->isAjaxRequest()) {
                $APPLICATION->RestartBuffer();
                header("Content-type:application/json");
                $this->arResult['SUCCESS'] = true;
                $this->arResult['ORDER_ID'] = $this->getOrderNumber();
                echo json_encode($this->arResult);
                die();
            }

            // если была передана финальная страница, перенаправляем на неё
            if (!empty($this->arParams['PATH_TO_SUCCESS'])) {
                LocalRedirect($this->arParams['PATH_TO_SUCCESS'] . '?ORDER_ID=' . $this->getOrderNumber());
            }

        } else {
            // получаем актуальную корзину с вычисленными правилами и скидками
            $this->getBasketItems();

            // при наличии не пустой корзины получаем данные для вывода
            if (count($this->arResult['ITEMS']) > 0) {
                $this->arResult['DELIVERY_LIST'] = $this->getOrderDeliveryList();
                $this->arResult['PAY_SYSTEM_LIST'] = $this->getOrderPaySystemList();
                $this->arResult['PROPS_LIST'] = $this->getOrderPropsList();
                $this->arResult['PERSON_TYPE_LIST'] = $this->getPersonTypes();
                $this->arResult['~DELIVERY_PRICE'] = $this->getDeliveryPrice();
                $this->arResult['DELIVERY_PRICE'] = $this->getDeliveryPriceFormat();
                $this->arResult['~ORDER_PRICE'] = $this->getOrderPrice();
                $this->arResult['ORDER_PRICE'] = $this->getOrderPriceFormat();
                $this->arResult['COUPON_DATA'] = $this->getCouponsData();
                $this->arResult['IS_PAYED'] = $this->order->isPaid();
                $this->arResult['USER_DESCRIPTION'] = $this->order->getField('USER_DESCRIPTION');
            }
        }

        // запрос AJAX без создания заказа, отдаём JSON
        if ($this->request['MODE'] == $this->arResult['HIDDEN'] && $this->request->isAjaxRequest()) {
            $APPLICATION->RestartBuffer();
            header("Content-type:application/json");
            echo json_encode($this->arResult);
            die();
        }

        // подключаем шаблон
        $this->IncludeComponentTemplate();
    }

    /**
     * Создаём объект заказа, добавляем в него все необходимые поля
     * если передано 'SAVE' == 'Y', сохраняем заказ в БД
     */
    private function createVirtualOrder()
    {
        global $USER;

        try {
            // Получаем актуальную корзину, текущую или из заказа, если его ID был передан в качестве 'ORDER_ID'
            if (!is_numeric($this->request->get('ORDER_ID'))) {
                $basket = Basket::loadItemsForFUser(Fuser::getId(), $this->getSiteId());

            } else {
                if ($this->arParams['USE_ORDER_NUMBER']) {
                    $basket = Order::loadByAccountNumber($this->request->get('ORDER_ID'))->getBasket();
                } else {
                    $basket = Order::load($this->request->get('ORDER_ID'))->getBasket();
                }
            }

            if (count($basket->getOrderableItems()) > 0) {
                // Ищем пользователя, или создаём, авторизуем
                if (!$USER->IsAuthorized()) {
                    $intUserId = $this->getUserId();
                    $USER->Authorize($intUserId);
                }

                // Создаём или получаем заказ
                if (!is_numeric($this->request->get('ORDER_ID'))) {
                    $this->order = Order::create($this->getSiteId(), $USER->GetID());
                    $this->order->setBasket($basket);

                } else {
                    if ($this->arParams['USE_ORDER_NUMBER']) {
                        $this->order = Order::loadByAccountNumber($this->request->get('ORDER_ID'));
                    } else {
                        $this->order = Order::load($this->request->get('ORDER_ID'));
                    }
                }
                $this->order->setPersonTypeId($this->arParams['PERSON_TYPE_ID']);


                if (!is_numeric($this->request->get('ORDER_ID'))) {
                    // отгрузки и служба доставки
                    /* @var \Bitrix\Sale\ShipmentCollection $shipmentCollection */
                    $shipmentCollection = $this->order->getShipmentCollection();
                    $shipment = $shipmentCollection->createItem();
                    $this->arDeliveryServiceAll = Bitrix\Sale\Delivery\Services\Manager::getRestrictedObjectsList($shipment);
                    $shipment = $this->initDelivery($shipment);
                    /** @var \Bitrix\Sale\ShipmentItemCollection $shipmentItemCollection */
                    $shipmentItemCollection = $shipment->getShipmentItemCollection();
                    $shipment->setField('CURRENCY', $this->order->getCurrency());
                    if ($this->arParams['DELIVERY_ID'] > 0) {
                        $shipment->setFields(array(
                            'DELIVERY_ID' => $this->arParams['DELIVERY_ID']
                        ));
                    }
                    foreach ($this->order->getBasket()->getOrderableItems() as $item) {
                        /**
                         * @var \Bitrix\Sale\BasketItem $item
                         * @var \Bitrix\Sale\ShipmentItem $shipmentItem
                         */
                        $shipmentItem = $shipmentItemCollection->createItem($item);
                        $shipmentItem->setQuantity($item->getQuantity());

                    }
                    $shipmentCollection->calculateDelivery();

                    // платёжная система
                    if ($this->arParams['PAYMENT_ID'] > 0) {
                        $paymentCollection = $this->order->getPaymentCollection();
                        $payment = $paymentCollection->createItem(
                            Bitrix\Sale\PaySystem\Manager::getObjectById(
                                $this->arParams['PAYMENT_ID']
                            )
                        );
                        $payment->setField('SUM', $this->order->getPrice());
                        $payment->setField('CURRENCY', $this->order->getCurrency());
                    }
                }

                // описание заказа
                if (!empty($this->request->get('USER_DESCRIPTION'))) {
                    $this->order->setField('USER_DESCRIPTION', $this->request->get('USER_DESCRIPTION'));
                }

                // cвойства заказа
                $this->setOrderProps();

                // promo
                DiscountCouponsManager::init();
                if (!empty($this->request->get('PROMO'))) {
                    DiscountCouponsManager::add($this->request->get('PROMO'));
                }

                $this->order->doFinalAction(true);

                if (isset($this->request['SAVE']) && $this->request['SAVE'] == 'Y' && empty($this->errors)) {

                    $res = $this->order->save();

                    if (!$res->isSuccess()) {
                        /** @var $error \Bitrix\Sale\ResultError */
                        $arErrors = $res->getErrors();
                        foreach ($arErrors as $error) {
                            $this->errors[] = $error->getMessage();
                        }

                    } else {
                        $this->initAffiliate();

                        // Опдлата баллами
                        if ((int)$this->request->get('PAY_BONUS') > 0) {
                            // оплата баллами
                            $withdrawSum = CSaleUserAccount::Withdraw(
                                $USER->GetID(),
                                $this->request->get('PAY_BONUS'),
                                $this->order->getCurrency(),
                                $this->order->getId()
                            );

                            if ($withdrawSum > 0) {
                                CSaleOrder::Update($this->order->getId(), array('SUM_PAID' => $withdrawSum));
                            }
                        }
                    }
                }

            }

        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    /**
     * Получаем содержимое корзины, рассчитываем все скидки и правила
     */
    private function getBasketItems()
    {
        /**
         * @var \Bitrix\Sale\BasketItem $item
         */
        $this->arResult['ITEMS'] = array();

        if (!empty($this->order)) {
            $arDiscountData = $this->getDiscountSum();
            $basket = $this->order->getBasket();
            $strCurrency = $this->order->getCurrency();
            $this->arResult['TOTAL_QUANTITY'] = 0;
            $this->arResult['~TOTAL_PRICE'] = 0;
            foreach ($basket->getOrderableItems() as $item) {
                // цены из рассчитанных скидок, если задано правило округления, оно будет применено
                $flRoundPrice = Price::roundPrice($item->getField('PRICE_TYPE_ID'), $arDiscountData['BASKET_ITEMS'][$item->getProductId()], $strCurrency);
                $flRoundBasePrice = Price::roundPrice($item->getField('PRICE_TYPE_ID'), $item->getPrice(), $strCurrency);
                $arFields = array(
                    'ID' => $item->getId(),
                    'EXTERNAL_ID' => $this->getExternalId($item->getProductId()),
                    'PRODUCT_ID' => $item->getProductId(),
                    'PARENT_NAME' => $this->getParentName($item->getProductId()),
                    'NAME' => $item->getField('NAME'),
                    'DETAIL_PAGE_URL' => $this->getDetailPageUrl($item->getProductId()),
                    'QUANTITY' => $item->getQuantity(),
                    '~PRICE' => $flRoundPrice,
                    'PRICE' => CCurrencyLang::CurrencyFormat($flRoundPrice, $strCurrency),
                    '~BASE_PRICE' => $flRoundBasePrice,
                    'BASE_PRICE' => CCurrencyLang::CurrencyFormat($flRoundBasePrice, $strCurrency),
                    '~FINAL_BASE_PRICE' => $flRoundBasePrice * $item->getQuantity(),
                    'FINAL_BASE_PRICE' => CCurrencyLang::CurrencyFormat(($flRoundBasePrice * $item->getQuantity()), $strCurrency),
                    '~FINAL_PRICE' => $flRoundPrice * $item->getQuantity(),
                    'FINAL_PRICE' => CCurrencyLang::CurrencyFormat(($flRoundPrice * $item->getQuantity()), $strCurrency),
                    'IMAGE' => $this->getImage($item->getProductId()),
                    'PREVIEW_TEXT' => $this->getPreviewText($item->getProductId()),
                    'PRICE_TYPE_ID' => $item->getField('PRICE_TYPE_ID'),
                    'PROPERTIES' => $this->getProperties($item->getProductId())
                );

                $this->arResult['TOTAL_QUANTITY'] += $item->getQuantity();
                $this->arResult['~TOTAL_PRICE'] += $flRoundPrice * $item->getQuantity();

                if (empty($arFields['PARENT_NAME'])) {
                    $arFields['PARENT_NAME'] = $arFields['NAME'];
                }

                $this->arResult['ITEMS'][] = $arFields;
            }
            $this->arResult['TOTAL_PRICE'] = CCurrencyLang::CurrencyFormat($this->arResult['~TOTAL_PRICE'], $strCurrency);
        }
    }

    /**
     * Рассчитываем скидки и применяем правила для корзины.
     *
     * @return array
     */
    private function getDiscountSum()
    {
        global $USER;

        $basket = Basket::loadItemsForFUser(Fuser::getId(), $this->getSiteId());

        $arBasketItems = array();

        foreach ($basket as $basketItem) {
            $arBasketItems[] = array(
                'PRODUCT_ID' => $basketItem->getProductId(),
                'PRODUCT_PRICE_ID' => $basketItem->getField('PRODUCT_PRICE_ID'),
                'PRICE' => $basketItem->getPrice(),
                'BASE_PRICE' => $basketItem->getBasePrice(),
                'QUANTITY' => $basketItem->getQuantity(),
                'LID' => $basketItem->getField('LID'),
                'MODULE' => $basketItem->getField('MODULE')
            );
        }

        $arOrder = array(
            'SITE_ID' => $this->getSiteId(),
            'USER_ID' => $USER->GetID(),
            'ORDER_PRICE' => $basket->getPrice(),
            'ORDER_WEIGHT' => $basket->getWeight(),
            'BASKET_ITEMS' => $arBasketItems
        );

        $arOptions = array(
            'COUNT_DISCOUNT_4_ALL_QUANTITY' => 'Y',
        );

        $arErrors = array();

        \CSaleDiscount::DoProcessOrder($arOrder, $arOptions, $arErrors);

        $arResult = array(
            'TOTAL_PRICE' => $arOrder['ORDER_PRICE'],
            'BASKET_ITEMS' => array()
        );

        foreach ($arOrder['BASKET_ITEMS'] as $arItem) {
            $arResult['BASKET_ITEMS'][$arItem['PRODUCT_ID']] = $arItem['PRICE'];
            $arResult['BASKET_ITEMS']['~' . $arItem['PRODUCT_ID']] = $arItem;
        }

        return $arResult;
    }

    /**
     * Получить XML_ID товара.
     *
     * @param $intId
     * @return mixed|string
     */
    private function getExternalId($intId)
    {
        $strExternalID = '';
        $arFilter = array(
            'ID' => $intId
        );
        $arSelect = array(
            'XML_ID'
        );
        $dbElement = CIBlockElement::GetList(array(), $arFilter, false, false, $arSelect);
        if ($arFields = $dbElement->GetNext()) {
            $strExternalID = $arFields['XML_ID'];
        }

        return $strExternalID;
    }

    /**
     * Получаем имя основного товара если передано торговое предложение.
     *
     * @param $intId
     * @return mixed|string
     */
    private function getParentName($intId)
    {
        $strName = '';

        $arFields = CCatalogSku::GetProductInfo($intId);
        if (is_array($arFields)) {
            $intId = $arFields['ID'];
        }

        $dbElement = CIBlockElement::GetByID($intId);
        if ($arFields = $dbElement->GetNext()) {
            $strName = $arFields['NAME'];
        }

        return $strName;
    }

    /**
     * Ссылка на товар в каталоге
     * @param $intId
     * @return mixed|string
     */
    private function getDetailPageUrl($intId)
    {
        $strDetailPageUrl = '';

        $arFields = CCatalogSku::GetProductInfo($intId);
        if (is_array($arFields)) {
            $intId = $arFields['ID'];
        }

        $dbElement = CIBlockElement::GetByID($intId);
        if ($arFields = $dbElement->GetNext()) {
            $strDetailPageUrl = $arFields['DETAIL_PAGE_URL'];
        }

        return $strDetailPageUrl;
    }

    /**
     *  Изображение товара, если передано торговое предложение
     *  будет получено изображение основного товара. если товар не имеет изображений
     *  вернётся дефолтное изображение, если оно было установлено в настройках.
     *  Если были заданы рамеры изображения и оригинал больше, будет выполнен ресайз.
     *
     * @param $intId
     * @return mixed|string
     */
    private function getImage($intId)
    {
        $strImagePath = '';

        $arFields = CCatalogSku::GetProductInfo($intId);
        if (is_array($arFields)) {
            $intId = $arFields['ID'];
        }

        $dbElement = CIBlockElement::GetByID($intId);
        if ($arFields = $dbElement->GetNext()) {
            $intImageId = 0;

            if ((int)$arFields['PREVIEW_PICTURE'] > 0) {
                $intImageId = (int)$arFields['PREVIEW_PICTURE'];

            } elseif ((int)$arFields['DETAIL_PICTURE'] > 0) {
                $intImageId = (int)$arFields['DETAIL_PICTURE'];

            } elseif (!empty($this->arParams['NO_PHOTO'])) {
                $intImageId = CFile::SaveFile(CFile::MakeFileArray($this->arParams['NO_PHOTO']), 'catalog');
            }

            if ($intImageId > 0) {
                $arSize = array(
                    'width' => $this->arParams['IMAGE_WIDTH'],
                    'height' => $this->arParams['IMAGE_HEIGHT']
                );
                $arImage = CFile::ResizeImageGet($intImageId, $arSize, BX_RESIZE_IMAGE_PROPORTIONAL);
                $strImagePath = $arImage['src'];
            }
        }

        return $strImagePath;
    }

    /**
     *  Описание анонса основного товара если передано торговое предложение.
     *
     * @param $intId
     * @return mixed|string
     */
    private function getPreviewText($intId)
    {
        $strText = '';

        $arFields = CCatalogSku::GetProductInfo($intId);
        if (is_array($arFields)) {
            $intId = $arFields['ID'];
        }

        $dbElement = CIBlockElement::GetByID($intId);
        if ($arFields = $dbElement->GetNext()) {
            $strText = $arFields['PREVIEW_TEXT'];
        }

        return $strText;
    }

    /**
     * Свойства товара.
     *
     * @param $intId
     * @return array
     */
    private function getProperties($intId)
    {
        $arProperties = array();

        $dbElement = \CIBlockElement::GetByID($intId);
        $element = $dbElement->GetNextElement();
        $arProperty = $element->GetProperties();
        foreach ($this->arParams['PROPERTY_LIST'] as $strProperty) {
            $arProperties[$strProperty] = $arProperty[$strProperty];
        }

        return $arProperties;
    }

    /**
     * Возвращает ID пользователя.
     * Сначала происходит поиск пользователя по логину,
     * если такого нет, то создаётся новый с использованием переданных полей заказа.
     * После создания формируется и отправляется письмо, если в настройках был передан кош почтового события.
     * Если пользователя не получилось создать, возвращаем ID стандартного анонима Битрикса.
     *
     * @return bool|int|mixed
     */
    private function getUserId()
    {
        $intUserId = 0;

        if (!empty($this->request->get($this->arParams['NEW_USER_LOGIN_FIELD']))) {
            $strLogin = $this->request->get($this->arParams['NEW_USER_LOGIN_FIELD']);

            $arUserParams = array(
                'LOGIN' => $strLogin
            );
            if (!empty($this->request->get('PHONE'))) {
                $arUserParams['PERSONAL_MOBILE'] = $this->request->get('PHONE');
            }
            if (!empty($this->request->get('NAME'))) {
                $arUserParams['NAME'] = $this->request->get('NAME');
            }
            if (!empty($this->request->get('EMAIL'))) {
                $arUserParams['EMAIL'] = $this->request->get('EMAIL');
            }

            try {
                $dbUser = \CUser::GetByLogin($strLogin);
                if ($arUser = $dbUser->Fetch()) {
                    // Пользователь существует
                    $arUser['IS_NEW'] = false;

                } else {
                    // Пользователь новый
                    if (empty($arUserParams['PASSWORD'])) {
                        $arUserParams['PASSWORD'] = uniqid();
                        $arUserParams['CONFIRM_PASSWORD'] = $arUserParams['PASSWORD'];
                    }
                    $user = new \CUser;;
                    $intUserID = $user->Add($arUserParams);

                    if (intval($intUserID)) {
                        $arUser = $arUserParams;
                        $arUser['ID'] = $intUserID;
                        $arUser['IS_NEW'] = true;

                    } else {
                        throw new SystemException($user->LAST_ERROR);
                    }
                }

                if (!empty($this->arParams['NEW_USER_EVENT_CODE'])) {
                    // был создан аккаунт, отправим письмо
                    $arMailFields = array(
                        'EVENT_NAME' => $this->arParams['NEW_USER_EVENT_CODE'],
                        'LID' => $this->getSiteId(),
                        'C_FIELDS' => array(
                            'NAME' => $arUser['NAME'],
                            'LOGIN' => $this->request->get('PHONE'),
                            'EMAIL' => $arUser['EMAIL'],
                            'PASSWORD' => $arUser['PASSWORD'],
                        )
                    );
                    Event::send($arMailFields);
                }

                if ($arUser !== false) {
                    $intUserId = $arUser['ID'];
                }
            } catch (SystemException $e) {
                $intUserId = CSaleUser::GetAnonymousUserID();
            }
        }

        if ($intUserId == 0) {
            $intUserId = CSaleUser::GetAnonymousUserID();
        }

        return $intUserId;
    }

    /**
     * Получаем все данные о применённых скидках и уже использованых купонах.
     *
     * @return array|bool|mixed
     */
    private function getCouponsData()
    {
        $arCouponList = array();
        $arCoupons = DiscountCouponsManager::get(true, array(), true, true);
        if (!empty($arCoupons)) {
            foreach ($arCoupons as &$oneCoupon) {
                if ($oneCoupon['STATUS'] == DiscountCouponsManager::STATUS_NOT_FOUND || $oneCoupon['STATUS'] == DiscountCouponsManager::STATUS_FREEZE) {
                    $oneCoupon['JS_STATUS'] = 'BAD';
                } elseif ($oneCoupon['STATUS'] == DiscountCouponsManager::STATUS_NOT_APPLYED || $oneCoupon['STATUS'] == DiscountCouponsManager::STATUS_ENTERED) {
                    $oneCoupon['JS_STATUS'] = 'ENTERED';
                } else {
                    $oneCoupon['JS_STATUS'] = 'APPLIED';
                }

                $oneCoupon['JS_CHECK_CODE'] = '';
                if (isset($oneCoupon['CHECK_CODE_TEXT'])) {
                    $oneCoupon['JS_CHECK_CODE'] = is_array($oneCoupon['CHECK_CODE_TEXT'])
                        ? implode('<br>', $oneCoupon['CHECK_CODE_TEXT'])
                        : $oneCoupon['CHECK_CODE_TEXT'];
                }

                $arCouponList[] = $oneCoupon;
            }

            unset($oneCoupon);
            $arCouponList = array_values($arCoupons);
        }
        unset($arCoupons);

        return $arCouponList;
    }

    /**
     * Общая стоимость заказа.
     *
     * @return float
     */
    public function getOrderPrice()
    {
        return $this->order->getPrice();
    }

    /**
     * Общая стоимость заказа, форматированная согласно шаблону сайта.
     *
     * @return string
     */
    private function getOrderPriceFormat()
    {
        return CCurrencyLang::CurrencyFormat($this->getOrderPrice(), $this->order->getCurrency());
    }

    /**
     * Стоимость доставки.
     *
     * @return mixed
     */
    private function getDeliveryPrice()
    {
        return $this->order->getDeliveryPrice();
    }

    /**
     * Стоимость доставки, форматированная согласно шаблону сайта.
     *
     * @return string
     */
    private function getDeliveryPriceFormat()
    {
        return CCurrencyLang::CurrencyFormat($this->order->getDeliveryPrice(), $this->order->getCurrency());
    }

    /**
     * ID заказа либо номер, если соответствующая опция установлена в настройках.
     *
     * @return mixed
     */
    private function getOrderNumber()
    {
        if ($this->arParams['USE_ORDER_NUMBER']) {
            $orderNum = $this->order->getField('ACCOUNT_NUMBER');

        } else {
            $orderNum = $this->order->getField('ID');
        }
        return $orderNum;
    }

    /**
     *  Сохраняем в заказ переданные из формы значения свойств.
     */
    private function setOrderProps()
    {
        /** @var \Bitrix\Sale\PropertyValue $prop */
        foreach ($this->order->getPropertyCollection() as $prop) {

            $arPropertyFields = $prop->getProperty();

            $value = $arPropertyFields['DEFAULT_VALUE'];

            foreach ($this->request as $key => $val) {
                if (strtolower($key) == strtolower($arPropertyFields['CODE'])) {
                    $value = $val;
                }
            }

            // required
            if ($arPropertyFields['REQUIRED'] == 'Y' && empty($value) && $this->request['save'] == 'Y') {
                $this->errors[] = 'Поле "' . $arPropertyFields['NAME'] . '" обязательно для заполнения!';
                continue;
            }

            if (!empty($value)) {
                $prop->setValue($value);
            }
        }
    }

    /**
     * ID выбранной доставки.
     *
     * @return int
     */
    private function getSelectedDelivery()
    {
        $shipment = false;
        $intDeliveryId = 0;
        foreach ($this->order->getShipmentCollection() as $shipmentItem) {
            if (!$shipmentItem->isSystem()) {
                $shipment = $shipmentItem;
                break;
            }
        }
        if ($shipment) {
            $obDelivery = $shipment->getDelivery();
            if (is_object($obDelivery)) {
                $intDeliveryId = $obDelivery->getId();
            }
        }

        return $intDeliveryId;
    }

    /**
     * ID выбранного склада
     * @return int
     */
    private function getSelectedStore()
    {
        $shipment = false;
        $intStoreID = 0;
        foreach ($this->order->getShipmentCollection() as $shipmentItem) {
            if (!$shipmentItem->isSystem()) {
                $shipment = $shipmentItem;
                break;
            }
        }
        if ($shipment) {
            $intStoreID = $shipment->getStoreId();
        }

        return $intStoreID;
    }

    /**
     * Список доступных доставок разбитый на соответствующие склады.
     *
     * @return array
     * @throws SystemException
     * @throws \Bitrix\Main\NotSupportedException
     */
    private function getOrderDeliveryList()
    {
        $arDeliveryList = array();

        $deliveries = $this->arDeliveryServiceAll;

        $i = 0;
        foreach ($deliveries as $key => $deliveryObj) {

            $clonedOrder = $this->order->createClone();
            /** @var Shipment $clonedShipment */
            $clonedShipment = $this->getCurrentShipment($clonedOrder);
            $clonedShipment->setField('CUSTOM_PRICE_DELIVERY', 'N');


            $arDelivery = array();

            $clonedShipment->setField('DELIVERY_ID', $deliveryObj->getId());
            $clonedOrder->getShipmentCollection()->calculateDelivery();
            $calcResult = $deliveryObj->calculate($clonedShipment);
            $calcOrder = $clonedOrder;

            // склады
            $arStores = Bitrix\Sale\Delivery\ExtraServices\Manager::getStoresFields($deliveryObj->getId());
            $arDelivery['STORES'] = $this->getStores($arStores['PARAMS']['STORES']);
            $arDelivery['EXTRA'] = Bitrix\Sale\Delivery\ExtraServices\Manager::getExtraServicesList($deliveryObj->getId(), false);


            if ($calcResult->isSuccess()) {
                $arDelivery['PRICE'] = Bitrix\Sale\PriceMaths::roundByFormatCurrency($calcResult->getPrice(), $calcOrder->getCurrency());
                $arDelivery['PRICE_FORMATED'] = SaleFormatCurrency($arDelivery['PRICE'], $calcOrder->getCurrency());

                $currentCalcDeliveryPrice = Bitrix\Sale\PriceMaths::roundByFormatCurrency($calcOrder->getDeliveryPrice(), $calcOrder->getCurrency());
                if ($currentCalcDeliveryPrice >= 0 && $arDelivery['PRICE'] != $currentCalcDeliveryPrice) {
                    $arDelivery['DELIVERY_DISCOUNT_PRICE'] = $currentCalcDeliveryPrice;
                    $arDelivery['DELIVERY_DISCOUNT_PRICE_FORMATED'] = SaleFormatCurrency($arDelivery['DELIVERY_DISCOUNT_PRICE'], $calcOrder->getCurrency());
                }

                if (strlen($calcResult->getPeriodDescription()) > 0) {
                    $arDelivery['PERIOD_TEXT'] = $calcResult->getPeriodDescription();
                }

                $arDelivery['NAME'] = $deliveryObj->getName();
                $arDelivery['NAME_WITH_PARENT'] = $deliveryObj->getNameWithParent();
                $arDelivery['DESCRIPTION'] = $deliveryObj->getDescription();
                $arDelivery['ID'] = $deliveryObj->getId();
                $arDelivery['PARENT_ID'] = $deliveryObj->getParentId();
                $arDelivery['SELECTED'] = false;


                $intSelectedDelivery = $this->getSelectedDelivery();
                if (($intSelectedDelivery == 0 && $i == 0) || $intSelectedDelivery == $arDelivery['ID']) {
                    $arDelivery['SELECTED'] = true;
                }

                $arDeliveryList[$arDelivery['ID']] = $arDelivery;
                $i++;
            }
        }

        Bitrix\Sale\Compatible\DiscountCompatibility::revertUsageCompatible();

        return $arDeliveryList;
    }

    /**
     * Список доступных складов.
     *
     * @param $arStores
     * @return mixed
     */
    private function getStores($arStores)
    {
        $intSelectedStoreID = $this->getSelectedStore();
        $i = 0;
        foreach ($arStores as $k => $arStore) {
            $arFilter = array(
                'ACTIVE' => 'Y',
                'ID' => $arStore['ID']
            );
            $arSelect = array(
                'ID',
                'TITLE'
            );
            $dbResult = CCatalogStore::GetList(array(), $arFilter, false, false, $arSelect);
            if ($arFields = $dbResult->GetNext()) {
                $arFields['SELECTED'] = false;
                if (($intSelectedStoreID == 0 && $i == 0) || $intSelectedStoreID == $arFields['ID']) {
                    $arFields['SELECTED'] = true;
                }
                $arStores[$k] = $arFields;
            } else {
                unset($arStores[$k]);
            }
            $i++;
        }
        return $arStores;
    }

    /**
     * Возвращает реальную отгрузку, минуя системную.
     *
     * @param $order
     * @return bool|mixed|Shipment
     */
    private function getCurrentShipment($order)
    {
        /** @var Shipment $shipment */
        /** @var Order $order */
        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem())
                return $shipment;
        }

        return false;
    }

    /**
     * Список доступных платёжных систем.
     *
     * @return array
     */
    private function getOrderPaySystemList()
    {
        $obPayment = $this->order->getPaymentCollection()->createItem();
        $arPaySystemList = Bitrix\Sale\PaySystem\Manager::getListWithRestrictions($obPayment);

        $intSelectedPaySystemID = $this->getSelectedPaySystem();

        $i = 0;
        foreach ($arPaySystemList as $k => $arPaySystem) {
            $arPaySystemList[$k]['SELECTED'] = false;
            if (($intSelectedPaySystemID == 0 && $i == 0) || $intSelectedPaySystemID == $arPaySystem['ID']) {
                $arPaySystemList[$k]['SELECTED'] = true;
            }
            $i++;
            if (is_numeric($arPaySystem['LOGOTIP'])) {
                $arPaySystemList[$k]['~LOGOTIP'] = $arPaySystem['LOGOTIP'];
                $arPaySystemList[$k]['LOGOTIP'] = CFile::GetPath($arPaySystem['LOGOTIP']);
            }
        }

        return $arPaySystemList;
    }

    /**
     * Выбранная платёжная система.
     *
     * @return int
     */
    private function getSelectedPaySystem()
    {
        $intSelectedPaySystemID = array_shift($this->order->getPaymentSystemId());

        return (int)$intSelectedPaySystemID;
    }

    /**
     * Список свойств заказа разбитый по группам, с уже введёнными значениями.
     *
     * @return array
     */
    private function getOrderPropsList()
    {
        $arPropsList = array();

        $dbPropsGroup = CSaleOrderPropsGroup::GetList(
            array('SORT' => 'ASC'),
            array('PERSON_TYPE_ID' => $this->order->getPersonTypeId()),
            false,
            false,
            array()
        );

        while ($arFields = $dbPropsGroup->GetNext()) {
            $arFields['PROPERTIES'] = array();
            $arPropsList[] = $arFields;
        }

        /** @var \Bitrix\Sale\PropertyValue $prop */
        foreach ($this->order->getPropertyCollection() as $prop) {
            $arProperty = $prop->getProperty();

            if ($arProperty['PERSON_TYPE_ID'] != $this->order->getPersonTypeId()) {
                continue;
            }

            if (is_array($arProperty['RELATION'])) {
                $isAllow = false;
                foreach ($arProperty['RELATION'] as $arData) {
                    if ($arData['ENTITY_TYPE'] == 'D') {
                        if ($arData['ENTITY_ID'] == $this->getSelectedDelivery()) {
                            $isAllow = true;
                            continue;
                        }
                    } elseif ($arData['ENTITY_TYPE'] == 'P') {
                        if ($arData['ENTITY_ID'] == $this->getSelectedPaySystem()) {
                            $isAllow = true;
                            continue;
                        }
                    }
                }

                if (!$isAllow) {
                    continue;
                }
            }

            $arProperty['~VALUE'] = $prop->getValue();
            $arProperty['VALUE'] = $prop->getViewHtml();

            foreach ($arPropsList as $k => $arGroup) {
                if ($arGroup['ID'] == $arProperty['PROPS_GROUP_ID']) {
                    $arPropsList[$k]['PROPERTIES'][] = $arProperty;
                    //break;
                }
            }
        }

        usort($arPropsList, function ($a, $b) {
            return ($a['SORT'] - $b['SORT']);
        });

        return $arPropsList;
    }

    /**
     * Список доступных типов покупателя.
     *
     * @return array
     */
    private function getPersonTypes()
    {
        $arPersonalTypes = array();

        $arOrder = array(
            'SORT' => 'ASC'
        );
        $arFilter = array(
            'LID' => Context::getCurrent()->getSite()
        );
        $dbPtype = CSalePersonType::GetList($arOrder, $arFilter);
        while ($arFields = $dbPtype->GetNext()) {
            $arFields['SELECTED'] = false;
            if ($this->order->getPersonTypeId() == $arFields['ID']) {
                $arFields['SELECTED'] = true;
            }
            $arPersonalTypes[] = $arFields;
        }

        return $arPersonalTypes;
    }

    /**
     * Инициализация системы доставки.
     * Усттанавливается оставка и склад. если были переданы.
     * Создаётся отгрузка.
     *
     * @param $shipment
     * @return mixed
     * @throws SystemException
     * @throws \Bitrix\Main\ArgumentException
     */
    private function initDelivery($shipment)
    {
        $deliveryId = (int)$this->request['DELIVERY_ID'];
        /** @var \Bitrix\Sale\ShipmentCollection $shipmentCollection */
        $shipmentCollection = $shipment->getCollection();
        $order = $shipmentCollection->getOrder();

        if (!empty($this->arDeliveryServiceAll)) {
            if (isset($this->arDeliveryServiceAll[$deliveryId])) {
                $deliveryObj = $this->arDeliveryServiceAll[$deliveryId];
            } else {
                $deliveryObj = reset($this->arDeliveryServiceAll);

                if (!empty($deliveryId)) {
                    $this->addWarning("DELIVERY_CHANGE_WARNING", 'DELIVERY');
                }

                $deliveryId = $deliveryObj->getId();
            }

            if ($deliveryObj->isProfile()) {
                $name = $deliveryObj->getNameWithParent();
            } else {
                $name = $deliveryObj->getName();
            }

            $order->isStartField();

            $shipment->setFields(array(
                'DELIVERY_ID' => $deliveryId,
                'DELIVERY_NAME' => $name,
                'CURRENCY' => $order->getCurrency()
            ));

            $deliveryStoreList = Bitrix\Sale\Delivery\ExtraServices\Manager::getStoresList($deliveryId);
            if (!empty($deliveryStoreList)) {
                $intStoreId = $this->request['STORE_ID'];
                if ($this->request['STORE_ID'] <= 0 || !in_array($this->request['STORE_ID'], $deliveryStoreList)) {
                    $intStoreId = current($deliveryStoreList);
                }

                $shipment->setStoreId($intStoreId);
            }

        } else {
            $service = Bitrix\Sale\Delivery\Services\Manager::getById(Bitrix\Sale\Delivery\Services\EmptyDeliveryService::getEmptyDeliveryServiceId());
            $shipment->setFields(array(
                'DELIVERY_ID' => $service['ID'],
                'DELIVERY_NAME' => $service['NAME'],
                'CURRENCY' => $order->getCurrency()
            ));
        }

        return $shipment;
    }
}
