<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 *
 */

declare(strict_types=1);

namespace BaksDev\Orders\Telegram\Messenger;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Core\Twig\TemplateExtension;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\OrderDetail\OrderDetailInterface;
use BaksDev\Orders\Order\Repository\OrderDetail\OrderDetailResult;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusExtradition;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramDeleteMessageHandler;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardButton;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardMarkup;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Users\Profile\UserProfile\Repository\Authority\isGrantedByRole\isGrantedUserProfileByRoleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 *  Информация о заказе с действиями (кнопками) по ролям
 *
 * next:
 * @see TelegramExtraditionToDeliveryHandler
 * @see TelegramPackageToExtraditionStatusHandler
 */
#[AsMessageHandler()]
final class TelegramOrdersHandler
{
    private OrderUid $order;

    private UserProfileUid $profile;

    private UserProfileUid $authority;

    public function __construct(
        #[Target('telegramLogger')] private readonly LoggerInterface $logger,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private readonly isGrantedUserProfileByRoleInterface $userProfileWithRolesRepository,
        private readonly OrderDetailInterface $orderDetail,
        private readonly CurrentOrderEventInterface $currentOrderEventRepository,
        private readonly ReplyKeyboardMarkup $keyboardMarkup,
        private readonly TemplateExtension $templateExtension,
        private readonly Environment $environment,
        private readonly TelegramSendMessages $telegramSendMessage,
    ) {}

    public function __invoke(TelegramEndpointMessage $message): void
    {
        $telegramRequest = $message->getTelegramRequest();

        if(false === ($telegramRequest instanceof TelegramRequestIdentifier))
        {
            return;
        }

        /** Профиль пользователя по идентификатору чата Телеграм */
        $profile = $this->activeProfileByAccountTelegram
            ->findByChat($telegramRequest->getChatId());

        if(false === ($profile instanceof UserProfileUid))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.' Запрос от не авторизированного пользователя', [
                '$profile' => var_export($profile, true),
            ]);
            return;
        }

        $this->profile = $profile;

        /** Идентификатор заказа */
        $this->order = new OrderUid($telegramRequest->getIdentifier()); // package

        /** Текущее событие заказа */
        $orderEvent = $this->currentOrderEventRepository
            ->forOrder($this->order)
            ->find();

        if(false === ($orderEvent instanceof OrderEvent))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.' Событие заказа по идентификатору не найден ', [
                '$orderId' => var_export($this->order, true)
            ]);
            return;
        }

        /** Информация о заказе */
        $orderInfo = $this->orderDetail
            ->onOrder($this->order)
            ->find();

        if(false === ($orderInfo instanceof OrderDetailResult))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.' Заказ по идентификатору не найден ', [
                '$orderId' => var_export($this->order, true)
            ]);
            return;
        }

        /** Готовим сообщение для отправки */
        $this
            ->telegramSendMessage
            ->chanel($telegramRequest->getChatId());

        /** Шаблон сообщения */
        $template = $this->templateExtension->extends('@orders-telegram:bot/order.html.twig');

        try
        {
            $render = $this->environment->render($template, [
                'orderInfo' => $orderInfo,
            ]);
        }
        catch(\Exception $exception)
        {
            $this->logger->critical(__CLASS__.':'.__LINE__.'Ошибка рендера шаблона @orders-telegram:bot/order.html.twig', [
                '$exception' => $exception->getMessage(),
                'chatId' => $telegramRequest->getChatId(),
            ]);
            return;
        }

        /** Профиль, которому принадлежит заказ */
        $orderAuthority = $orderEvent->getOrderProfile();

        /** Если NULL - заказ новый -> отправляем информацию о заказе без действий */
        if(is_null($orderAuthority))
        {
            /** Кнопка Выход */
            $this->keyboardMarkup->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Выход')
                    ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
            );

            $this
                ->telegramSendMessage
                ->message($render)
                ->markup($this->keyboardMarkup)
                ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
                ->send();

            $message->complete();
            return;
        }

        $this->authority = $orderAuthority;

        /** По доверенности из заказа проверяем доступ у пользователя */
        $packageRole = $this->checkRole(OrderStatusPackage::getVoter());
        $extraditionRole = $this->checkRole(OrderStatusExtradition::getVoter());

        /** Добавляем кнопку действия для смены статуса - Доставка */
        if($extraditionRole and $orderEvent->isStatusEquals(OrderStatusExtradition::class))
        {
            $this->keyboard('Принять для доставки', TelegramExtraditionToDeliveryHandler::KEY);
        }

        /** Добавляем кнопку действия для смены статуса - Укомплектовать */
        if($packageRole and $orderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            $this->keyboard('Отправить на упаковку', TelegramPackageToExtraditionStatusHandler::KEY);
        }

        /** Кнопка Выход */
        $this->keyboardMarkup->addNewRow(
            (new ReplyKeyboardButton)
                ->setText('Выход')
                ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
        );

        $this
            ->telegramSendMessage
            ->message($render)
            ->markup($this->keyboardMarkup)
            ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
            ->send();

        $message->complete();
    }

    /** Проверяет доступ по роли */
    private function checkRole(string $role): bool
    {
        return $this->userProfileWithRolesRepository
            ->onProfile($this->profile)
            ->onAuthority($this->authority)
            ->onRoleVoter($role)
            ->isGranted();
    }

    /** Строим клавиатуру с кнопками действий в зависимости от статусов заказа и ролей профиля */
    private function keyboard(string $text, string $key): void
    {
        $callbackData = $key.'|'.$this->order;

        /** Кнопка назад */
        $backButton = new ReplyKeyboardButton;
        $backButton
            ->setText($text)
            ->setCallbackData($callbackData);

        $this->keyboardMarkup->addCurrentRow($backButton);
    }

}

