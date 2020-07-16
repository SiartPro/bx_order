<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Application;

/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */
/** @global CUser $USER */
/** @global CDatabase $DB */
/** @var CBitrixComponentTemplate $this */
/** @var string $templateName */
/** @var string $templateFile */
/** @var string $templateFolder */
/** @var string $componentPath */
/** @var CBitrixComponent $component */

$this->setFrameMode(false);
$request = Application::getInstance()->getContext()->getRequest();
?>

    <section class="checkout container">
        <h1>Оформление заказа</h1>
        <? if (empty($request->get('PHONE'))): ?>
            <form class="checkout-list" action="" method="post">
                <input type="hidden" name="first_step" value="Y">
                <input type="hidden" name="delivery_id" value="<?= $request->get('delivery_id') ?>">
                <input type="hidden" name="payment_id" value="2">
                <? if (is_numeric($request->get('pay_bonus'))): ?>
                    <input type="hidden" name="pay_bonus" value="<?= $request->get('pay_bonus') ?>">
                <? endif ?>
                <div class="checkout-form first-stage">
                    <label>Контактный телефон
                        <input
                                class="mask-phone"
                                type="tel" name="PHONE"
                                <? if (!empty($arResult['USER_DATA']['PERSONAL_MOBILE'])): ?>value="<?= $arResult['USER_DATA']['PERSONAL_MOBILE'] ?>" <? endif ?>
                                placeholder="+7(___)___-__-__"
                                required="">
                    </label>
                    <button class="checkout-form__submit-btn">
                        Продолжить оформление
                    </button>
                </div>
            </form>
        <? else: ?>
            <form class="checkout-list" action="" method="post">
                <input type="hidden" name="delivery_id" value="<?= $request->get('delivery_id') ?>">
                <input type="hidden" name="PHONE" value="<?= $request->get('PHONE') ?>">
                <? if (is_numeric($request->get('pay_bonus'))): ?>
                    <input type="hidden" name="pay_bonus" value="<?= $request->get('pay_bonus') ?>">
                <? endif ?>
                <input type="hidden" name="save" value="Y">
                <div class="checkout-form">
                    <div class="checkout-form__item  checkout-form__item--one">
                        <h2>Покупатель</h2>
                        <label>Электронная почта
                            <input type="email" name="EMAIL"
                                   <? if (!empty($arResult['USER_DATA']['EMAIL'])): ?>value="<?= $arResult['USER_DATA']['EMAIL'] ?>" <? endif; ?>
                                   required>
                        </label>
                        <label>Имя
                            <input type="text" name="NAME"
                                   <? if (!empty($arResult['USER_DATA']['NAME'])): ?>value="<?= $arResult['USER_DATA']['NAME'] ?>" <? endif; ?>
                                   required>
                        </label>
                        <label>Фамилия
                            <input type="text" name="SURNAME"
                                   <? if (!empty($arResult['USER_DATA']['LAST_NAME'])): ?>value="<?= $arResult['USER_DATA']['LAST_NAME'] ?>" <? endif ?>
                                   required>
                        </label>
                        <label class="checkout-info  checkout-checkbox">
                            <input type="checkbox" name="NEED_INFO" value="Y" checked>
                            <span class="checkbox-custom"></span>
                            Хочу получать информацию о скидках и акциях</label>
                    </div>
                    <div class="checkout-form__item  checkout-form__item--two">
                        <h2>Доставка</h2>
                        <?= $arResult['DELIVERY_LIST'][$_REQUEST['delivery_id']]['NAME'] ?>
                        <? /* foreach ($arResult['DELIVERY_LIST'] as $arDelivery): ?>
                        <label class="checkout-radio">
                            <input type="radio" name="delivery_id"
                                   value="<?= $arDelivery['ID'] ?>"<? if ($arDelivery['SELECTED']): ?> checked<? endif ?>>
                            <span class="radio-custom"></span><?= $arDelivery['NAME'] ?>
                        </label>
                    <? endforeach */ ?>

                        <label class="checkout-delivery-input">Адрес доставки
                            <input
                                    type="text"
                                    id="adress"
                                    name="ADDRESS"
                                    value="<? if (!empty($arResult['USER_DATA']['PERSONAL_NOTES'])): ?><?= $arResult['USER_DATA']['PERSONAL_NOTES'] ?><? elseif (!empty($request->get('address'))): ?><?= $request->get('address') ?><? endif ?>"
                                    placeholder="Москва, ул. Пушкина 4, корпус 12, кв 345, 15 этаж"
                                    disabled></label>

                        <button class="delivery-class__btn" type="button">Изменить адрес доставки</button>

                        <label class="checkout-checkbox">
                            <input type="checkbox" name="CALL_ME" value="Y" checked>
                            <span class="checkbox-custom"></span>
                            Перезвоните мне для уточнения деталей к заказу</label>
                    </div>
                    <div class="checkout-form__item  checkout-form__item--pay  checkout-form__item--three">
                        <h2>Оплата</h2>

                        <? foreach ($arResult['PAY_SYSTEM_LIST'] as $k => $arPayment): ?>
                            <input
                                    type="radio"
                                    id="payment-<?= $k ?>"
                                    name="payment_id"
                                    value="<?= $arPayment['ID'] ?>"
                                <? if ($arPayment['SELECTED']): ?> checked<? endif ?>>
                            <label class="payment-1" for="payment-<?= $k ?>">
                                <img class="payment-img" src="<?= $arPayment['LOGOTIP'] ?>" alt="<?= $arPayment['NAME'] ?>">
                                <span class="label-title"><?= $arPayment['NAME'] ?></span>
                                <span><?= $arPayment['DESCRIPTION'] ?></span>
                            </label>
                        <? endforeach ?>

                    </div>
                    <div class="checkout-form__item  checkout-form__item--four">
                        <h2>Комментарий к заказу</h2>
                        <label for="text-message">Комментарий
                            <textarea rows="7" name="user_description" id="text-message" maxlength="350"></textarea></label>
                        <span id="count_message"></span>
                    </div>
                    <button class="checkout-form__submit-btn no-mobile" onclick="yaCounter51418654.reachGoal('press-checkout-final'); return true;">
                        Оформить заказ
                    </button>

                </div>
                <div class="checkout-form__submit">
                    <h3 class="checkout-form__submit-title">Сумма заказа</h3>
                    <p class="checkout-form__submit-price"><?= $arResult['ORDER_PRICE'] ?></p>
                    <? /*<div class="checkout-form__submit-gift">
                    <p>Начислено <span>50 бесплатно</span></p>
                </div>*/ ?>
                    <label class="checkout-checkbox  checkout-form__submit-politics"><input type="checkbox" name="politics" value="Y"
                                                                                            checked><span
                                class="checkbox-custom"></span>Я согласен с <a href="/the-regulation-on-personal-data/">Политикой обработки
                            персональных данных</a></label>
                    <button class="checkout-form__submit-btn" onclick="yaCounter51418654.reachGoal('press-checkout-final'); return true;">
                        Оформить заказ
                    </button>
                </div>
            </form>
        <? endif ?>
    </section>

<?
//Bitrix\Main\Loader::includeModule('dk.helper');
//DK\Helper\Main\Debug::show($arResult['ERRORS'], true);
