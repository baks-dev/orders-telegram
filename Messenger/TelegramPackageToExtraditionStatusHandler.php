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
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Products\Stocks\Entity\Stock\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Stock\ProductStock;
use BaksDev\Products\Stocks\Repository\ProductStocksByOrder\ProductStocksByOrderInterface;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusPackage;
use BaksDev\Products\Stocks\UseCase\Admin\Extradition\ExtraditionProductStockDTO;
use BaksDev\Products\Stocks\UseCase\Admin\Extradition\ExtraditionProductStockHandler;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramDeleteMessageHandler;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardButton;
use BaksDev\Telegram\Builder\ReplyKeyboardMarkup\ReplyKeyboardMarkup;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отправляет заказ на упаковку
 *
 * меняет статус складской заявки с Package на Extradition
 *
 * prev @see TelegramOrdersHandler
 */
#[AsMessageHandler()]
final readonly class TelegramPackageToExtraditionStatusHandler
{
    public const string KEY = '6ou8hd8q';

    public function __construct(
        #[Target('telegramLogger')] private LoggerInterface $logger,
        private ReplyKeyboardMarkup $keyboardMarkup,
        private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private ProductStocksByOrderInterface $productStocksByOrder,
        private ExtraditionProductStockHandler $extraditionProductStockHandler,
        private TelegramSendMessages $telegramSendMessage,
    ) {}

    public function __invoke(TelegramEndpointMessage $message): void
    {

        $telegramRequest = $message->getTelegramRequest();

        /** Проверка на тип запроса */
        if(false === ($telegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        /** Проверка идентификатора кнопки */
        if(false === ($telegramRequest->getCall() === self::KEY))
        {
            return;
        }

        /** Профиль пользователя по id телеграм чата */
        $profile = $this->activeProfileByAccountTelegram->findByChat($telegramRequest->getChatId());

        if(false === ($profile instanceof UserProfileUid))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.'Запрос от не авторизированного пользователя', [
                '$profile' => $profile,
            ]);
            return;
        }

        $order = new OrderUid($telegramRequest->getIdentifier()); // package

        $productStockEvents = $this->productStocksByOrder
            ->onStatus(ProductStockStatusPackage::class)
            ->findByOrder($order);

        if(is_null($productStockEvents))
        {
            $this->logger->warning(__CLASS__.':'.__LINE__.'Не найдена заявка для заказа по статусу', [
                '$order' => var_export($order, true),
                'onStatusPackage',
            ]);
            return;
        }

        /** @var ProductStockEvent $productStockEvent */
        $productStockEvent = current($productStockEvents);

        $extraditionProductStockDTO = new ExtraditionProductStockDTO();
        $extraditionProductStockDTO->setFixed($profile);
        $productStockEvent->getDto($extraditionProductStockDTO);

        $handle = $this->extraditionProductStockHandler->handle($extraditionProductStockDTO);

        /** Готовим сообщение для отправки */
        $this
            ->telegramSendMessage
            ->chanel($telegramRequest->getChatId());

        if(false === $handle instanceof ProductStock)
        {
            $this->logger->critical(__CLASS__.':'.__LINE__.'Ошибка при изменении статуса заказа', [
                '$order' => var_export($order, true),
                '$handle' => var_export($handle, true),
            ]);

            /** Кнопка Выход */
            $this->keyboardMarkup->addNewRow(
                (new ReplyKeyboardButton)
                    ->setText('Выход')
                    ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
            );

            $this
                ->telegramSendMessage
                ->message('<b>Возникла ошибка при изменении статуса заказа</b>')
                ->markup($this->keyboardMarkup)
                ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
                ->send();

            $message->complete();
            return;
        }

        /** Кнопка Выход */
        $this->keyboardMarkup->addNewRow(
            (new ReplyKeyboardButton)
                ->setText('Выход')
                ->setCallbackData(TelegramDeleteMessageHandler::DELETE_KEY)
        );

        $text = sprintf('Заказ <b>#%s</b> упомлектован', $productStockEvent->getNumber());
        $this
            ->telegramSendMessage
            ->message($text)
            ->markup($this->keyboardMarkup)
            ->delete([$telegramRequest->getId(), $telegramRequest->getLast()])
            ->send();

        $message->complete();
    }

}

