<?
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use \Bitrix\Main\Loader;
use \Bitrix\Main\Mail\Event;
use \Bitrix\Currency\CurrencyManager;

Loader::includeModule('dk.helper');

CBitrixComponent::includeComponentClass('dk:basket');

class CDkOrder extends CDkBasket
{
    /**
     * @var \Bitrix\Sale\Order $order
     * @var array $errors
     * @var float $basketPrice
     * @var array $orderDeliveryList
     * @var string $coupon
     */
    protected $order;
    protected $errors = [];
    protected $basketPrice = 0;
    protected $arDeliveryServiceAll = [];
    protected $coupon = '';

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

        if (!Loader::includeModule('dk.helper')) {
            $this->errors[] = 'No dk.helper module';
        };
    }

    public function onPrepareComponentParams($arParams)
    {
        // Тип плательщика
        if (isset($arParams['PERSON_TYPE_ID']) && intval($arParams['PERSON_TYPE_ID']) > 0) {
            $arParams['PERSON_TYPE_ID'] = intval($arParams['PERSON_TYPE_ID']);
        } else {
            if (intval($this->request['person_type_id']) > 0) {
                $arParams['PERSON_TYPE_ID'] = intval($this->request['person_type_id']);
            } else {
                $arParams['PERSON_TYPE_ID'] = 1;
            }
        }

        // Ссылка на корзину
        if (empty($arParams['PATH_TO_BASKET'])) {
            $arParams['PATH_TO_BASKET'] = '/personal/cart/';
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

        // AJAX
        if (!empty($arParams['IS_AJAX'])) {
            $arParams['IS_AJAX'] = ($arParams['IS_AJAX'] == 'Y');
        } else {
            if (!empty($this->request['is_ajax'])) {
                $arParams['IS_AJAX'] = ($this->request['is_ajax'] == 'Y');
            } else {
                $arParams['IS_AJAX'] = false;
            }
        }

        return $arParams;
    }

    public function createVirtualOrder()
    {
        global $USER;

        try {
            $siteId = \Bitrix\Main\Context::getCurrent()->getSite();

            if (!is_numeric($_SESSION['PRE_ORDER_ID'])) {
                $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\CSaleBasket::GetBasketUserID(), $siteId);
                $basketItems = $basket->getOrderableItems();
                if (count($basketItems) == 0) {
                    LocalRedirect($this->arParams['PATH_TO_BASKET']);
                }

            } else {
                $basket = \Bitrix\Sale\Order::load($_SESSION['PRE_ORDER_ID'])->getBasket();
            }

            // user
            if (!$USER->IsAuthorized() && $this->request['save'] == 'Y') {
                $intUserId = 0;

                if (!empty($this->request->get('PHONE'))) {
                    $arParams = array(
                        'LOGIN' => $this->request->get('PHONE'),
                        'PERSONAL_MOBILE' => $this->request->get('PHONE')
                    );
                    if (!empty($this->request->get('NAME'))) {
                        $arParams['NAME'] = $this->request->get('NAME');
                    }
                    if (!empty($this->request->get('EMAIL'))) {
                        $arParams['EMAIL'] = $this->request->get('EMAIL');
                    }

                    try {
                        $arUser = \DK\Helper\Main\User::getByLogin($this->request->get('PHONE'), $arParams);

                        // был создан аккаунт, отправим письмо
                        $arMailFields = array(
                            'EVENT_NAME' => 'NEW_USER_NOTICE',
                            'LID' => $siteId,
                            'C_FIELDS' => array(
                                'NAME' => $arUser['NAME'],
                                'LOGIN' => $this->request->get('PHONE'),
                                'EMAIL' => $arUser['EMAIL'],
                                'PASSWORD' => $arUser['PASSWORD'],
                            )
                        );
                        Event::send($arMailFields);

                        if ($arUser !== false) {
                            $intUserId = $arUser['ID'];
                        }
                    } catch (\Bitrix\Main\SystemException $e) {
                        $intUserId = CSaleUser::GetAnonymousUserID();
                    }
                }

                if ($intUserId == 0) {
                    $intUserId = CSaleUser::GetAnonymousUserID();
                }

                $USER->Authorize($intUserId);
            }

            if (!is_numeric($_SESSION['PRE_ORDER_ID'])) {
                $this->order = \Bitrix\Sale\Order::create($siteId, \DK\Helper\Main\User::getID(true));
                $this->order->setBasket($basket);

            } else {
                $this->order = \Bitrix\Sale\Order::load($_SESSION['PRE_ORDER_ID']);
                //$this->order->setField('USER_ID', $USER->GetID());
            }

            $this->order->setPersonTypeId($this->arParams['PERSON_TYPE_ID']);


            if (!is_numeric($_SESSION['PRE_ORDER_ID'])) {
                // отгрузки и служба доставки
                /* @var \Bitrix\Sale\ShipmentCollection $shipmentCollection */
                $shipmentCollection = $this->order->getShipmentCollection();
                $shipment = $shipmentCollection->createItem();
                $this->arDeliveryServiceAll = \Bitrix\Sale\Delivery\Services\Manager::getRestrictedObjectsList($shipment);
                $shipment = $this->initDelivery($shipment);
                /** @var \Bitrix\Sale\ShipmentItemCollection $shipmentItemCollection */
                $shipmentItemCollection = $shipment->getShipmentItemCollection();
                $shipment->setField('CURRENCY', $this->order->getCurrency());
                if ((int)$this->request['delivery_id'] > 0) {
                    $shipment->setFields(array(
                        'DELIVERY_ID' => (int)$this->request['delivery_id']
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
                if ((int)$this->request['payment_id'] > 0) {
                    $paymentCollection = $this->order->getPaymentCollection();
                    $payment = $paymentCollection->createItem(
                        Bitrix\Sale\PaySystem\Manager::getObjectById(
                            intval($this->request['payment_id'])
                        )
                    );
                    $payment->setField('SUM', $this->order->getPrice());
                    $payment->setField('CURRENCY', $this->order->getCurrency());
                }
            }

            // описание заказа
            if (!empty($this->request->get('user_description'))) {
                $this->order->setField('USER_DESCRIPTION', $this->request->get('user_description'));
            }

            // cвойства заказа
            $this->setOrderProps();

            // promo
            /*\Bitrix\Sale\DiscountCouponsManager::init();
            if (!empty($this->request->get('promo'))) {
                $this->coupon = $this->request->get('promo');
                \Bitrix\Sale\DiscountCouponsManager::add($this->coupon);
            }*/

            $this->order->doFinalAction(true);

            if (isset($this->request['save']) && $this->request['save'] == 'Y' && empty($this->errors)) {

                if ($this->request->get('NEED_INFO') == 'Y') {
                    $subscription = new \CSubscription;
                    $subscription->Add(array('EMAIL' => $this->request->get('EMAIL'), 'ACTIVE' => 'Y', 'SEND_CONFIRM' => 'N'));
                }

                \DK\Helper\Sale\Order::setPropertyValueByCode($this->order, 'FIRST_STEP', 'N');

                $this->initAffiliate();

                $res = $this->order->save();

                if (!$res->isSuccess()) {
                    /** @var $error \Bitrix\Sale\ResultError */
                    $arErrors = $res->getErrors();
                    foreach ($arErrors as $error) {
                        $this->errors[] = $error->getMessage();
                    }

                } else {
                    CSaleOrder::Update($this->order->getId(), array('USER_ID' => $USER->GetID()));
                    if (is_numeric($this->request->get('pay_bonus'))) {
                        // оплата баллами
                        $withdrawSum = CSaleUserAccount::Withdraw(
                            $USER->GetID(),
                            $this->request->get('pay_bonus'),
                            CurrencyManager::getBaseCurrency(),
                            $this->order->getId()
                        );

                        \DK\Helper\Main\Debug::show($withdrawSum);

                        if ($withdrawSum > 0) {
                            CSaleOrder::Update($this->order->getId(), array('SUM_PAID' => $withdrawSum));
                        }


                    }
                    unset($_SESSION['PRE_ORDER_ID']);
                }

            } elseif (isset($this->request['first_step']) && $this->request['first_step'] == 'Y') {
                \DK\Helper\Sale\Order::setPropertyValueByCode($this->order, 'FIRST_STEP', 'Y');
                $res = $this->order->save();

                if (!$res->isSuccess()) {
                    /** @var $error \Bitrix\Sale\ResultError */
                    $arErrors = $res->getErrors();
                    foreach ($arErrors as $error) {
                        $this->errors[] = $error->getMessage();
                    }

                } else {
                    $_SESSION['PRE_ORDER_ID'] = $this->order->getId();
                }
            }

        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    public function getCouponsData()
    {
        $arCouponList = array();
        $arCoupons = \Bitrix\Sale\DiscountCouponsManager::get(true, array(), true, true);
        if (!empty($arCoupons)) {
            foreach ($arCoupons as &$oneCoupon) {
                if ($oneCoupon['STATUS'] == \Bitrix\Sale\DiscountCouponsManager::STATUS_NOT_FOUND || $oneCoupon['STATUS'] == \Bitrix\Sale\DiscountCouponsManager::STATUS_FREEZE) {
                    $oneCoupon['JS_STATUS'] = 'BAD';
                } elseif ($oneCoupon['STATUS'] == \Bitrix\Sale\DiscountCouponsManager::STATUS_NOT_APPLYED || $oneCoupon['STATUS'] == \Bitrix\Sale\DiscountCouponsManager::STATUS_ENTERED) {
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

    public function getUserDescription()
    {
        return $this->order->getField('USER_DESCRIPTION');
    }

    public function getOrderPrice()
    {
        $flPrice = (float)$this->order->getPrice();
        if (is_numeric($this->request->get('pay_bonus'))) {
            $flPrice = $flPrice - (float)$this->request->get('pay_bonus');
        }
        return $flPrice;
    }

    public function getOrderPriceFormat()
    {
        return CCurrencyLang::CurrencyFormat($this->getOrderPrice(), $this->order->getCurrency());
    }

    public function getDeliveryPrice()
    {
        return $this->order->getDeliveryPrice();
    }

    public function getDeliveryPriceFormat()
    {
        return CCurrencyLang::CurrencyFormat($this->order->getDeliveryPrice(), $this->order->getCurrency());
    }

    public function getOrderId()
    {
        return $this->order->getId();;
    }

    public function getOrderNumber()
    {
        return $this->order->getField('ACCOUNT_NUMBER');
    }

    public function isPaid()
    {
        return $this->order->isPaid();
    }

    public function getOrderDate()
    {
        return $this->order->getDateInsert();
    }

    protected function setOrderProps()
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

    protected function getSelectedDelivery()
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

    protected function getSelectedStore()
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

    public function getOrderDeliveryList()
    {
        $arDeliveryList = array();

        $deliveries = $this->arDeliveryServiceAll;

        $i = 0;
        foreach ($deliveries as $key => $deliveryObj) {

            $clonedOrder = $this->order->createClone();
            /** @var \Bitrix\Sale\Shipment $clonedShipment */
            $clonedShipment = $this->getCurrentShipment($clonedOrder);
            $clonedShipment->setField('CUSTOM_PRICE_DELIVERY', 'N');


            $arDelivery = array();

            $clonedShipment->setField('DELIVERY_ID', $deliveryObj->getId());
            $clonedOrder->getShipmentCollection()->calculateDelivery();
            $calcResult = $deliveryObj->calculate($clonedShipment);
            $calcOrder = $clonedOrder;

            // склады
            $arStores = \Bitrix\Sale\Delivery\ExtraServices\Manager::getStoresFields($deliveryObj->getId());
            $arDelivery['STORES'] = $this->getStores($arStores['PARAMS']['STORES']);
            $arDelivery['EXTRA'] = \Bitrix\Sale\Delivery\ExtraServices\Manager::getExtraServicesList($deliveryObj->getId(), false);


            if ($calcResult->isSuccess()) {
                $arDelivery['PRICE'] = \Bitrix\Sale\PriceMaths::roundByFormatCurrency($calcResult->getPrice(), $calcOrder->getCurrency());
                $arDelivery['PRICE_FORMATED'] = SaleFormatCurrency($arDelivery['PRICE'], $calcOrder->getCurrency());

                $currentCalcDeliveryPrice = \Bitrix\Sale\PriceMaths::roundByFormatCurrency($calcOrder->getDeliveryPrice(), $calcOrder->getCurrency());
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

        \Bitrix\Sale\Compatible\DiscountCompatibility::revertUsageCompatible();

        return $arDeliveryList;
    }

    protected function getStores($arStores)
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

    protected function getCurrentShipment(\Bitrix\Sale\Order $order)
    {
        /** @var Shipment $shipment */
        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem())
                return $shipment;
        }

        return null;
    }

    public function getOrderPaySystemList()
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

    protected function getSelectedPaySystem()
    {
        $intSelectedPaySystemID = array_shift($this->order->getPaymentSystemId());

        return (int)$intSelectedPaySystemID;
    }

    public function getOrderPropsList()
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

        /*usort($arPropsList, function ($a, $b) {
            return ($a['SORT'] - $b['SORT']);
        });*/

        return $arPropsList;
    }

    public function getPersonTypes()
    {
        $arPersonalTypes = array();

        $arOrder = array(
            'SORT' => 'ASC'
        );
        $arFilter = array(
            'LID' => \Bitrix\Main\Context::getCurrent()->getSite()
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

    public function getBasketPrice()
    {
        return $this->basketPrice;
    }

    public function getBasketPriceFormat()
    {
        return CCurrencyLang::CurrencyFormat($this->basketPrice, $this->order->getCurrency());
    }

    protected function initDelivery(\Bitrix\Sale\Shipment $shipment)
    {
        $deliveryId = (int)$this->request['delivery_id'];
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
                $intStoreId = $this->request['store_id'];
                if ($this->request['store_id'] <= 0 || !in_array($this->request['store_id'], $deliveryStoreList)) {
                    $intStoreId = current($deliveryStoreList);
                }

                $shipment->setStoreId($intStoreId);
            }

            /*$deliveryExtraServices = $this->arUserResult['DELIVERY_EXTRA_SERVICES'];
            if (is_array($deliveryExtraServices) && !empty($deliveryExtraServices[$deliveryId]))
            {
                $shipment->setExtraServices($deliveryExtraServices[$deliveryId]);
                $deliveryObj->getExtraServices()->setValues($deliveryExtraServices[$deliveryId]);
            }*/

            /*$shipmentCollection->calculateDelivery();

            $order->doFinalAction(true);*/
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

    public function getUserData()
    {
        $arUserData = array();
        $UserId = CUser::GetID();

        if (!empty($this->request->get('ADDRESS'))) {
            $userUp = new CUser;
            $fieldsUp = Array(
                "PERSONAL_NOTES" => $this->request->get('ADDRESS'),
            );
            $userUp->Update($UserId, $fieldsUp);
        }

        $rsUser = CUser::GetByID($UserId);
        $arUserData = $rsUser->Fetch();

        return $arUserData;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getResult()
    {
        parent::getBasketItems();
        return parent::getResult();
    }

    public function executeComponent()
    {

        parent::getBasketItems();
        return $this->__includeComponent();
    }

    protected function initAffiliate()
    {
        $affiliateID = CSaleAffiliate::GetAffiliate();
        if ($affiliateID > 0)
        {
            $dbAffiliate = CSaleAffiliate::GetList(array(), array("SITE_ID" => $this->getSiteId(), "ID" => $affiliateID));
            $arAffiliates = $dbAffiliate->Fetch();
            if (count($arAffiliates) > 1)
                $this->order->setField('AFFILIATE_ID', $affiliateID);
        }
    }
}
