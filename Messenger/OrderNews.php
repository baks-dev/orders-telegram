<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
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
 */

declare(strict_types=1);

namespace BaksDev\Orders\Telegram\Messenger;

use BaksDev\Auth\Telegram\Repository\AccountTelegramRole\AccountTelegramRoleInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderDetail\OrderDetailInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\User\Basket\OrderDTO;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Quantity\ProductModificationQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\Quantity\ProductVariationQuantity;
use BaksDev\Products\Product\Repository\CurrentQuantity\CurrentQuantityByEventInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Modification\CurrentQuantityByModificationInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Offer\CurrentQuantityByOfferInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Variation\CurrentQuantityByVariationInterface;
use BaksDev\Products\Stocks\Telegram\Messenger\Extradition\TelegramExtraditionProcess;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Users\Profile\Group\Repository\ProfilesByRole\ProfilesByRoleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OrderNews
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private TelegramSendMessage $telegramSendMessage;
    private AccountTelegramRoleInterface $accountTelegramRole;
    private OrderDetailInterface $orderDetail;


    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $ordersOrderLogger,
        TelegramSendMessage $telegramSendMessage,
        AccountTelegramRoleInterface $accountTelegramRole,
        OrderDetailInterface $orderDetail
    )
    {
        $this->entityManager = $entityManager;
        $this->entityManager->clear();

        $this->logger = $ordersOrderLogger;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->accountTelegramRole = $accountTelegramRole;
        $this->orderDetail = $orderDetail;
    }


    /**
     * Сообщение ставит продукцию в резерв
     */
    public function __invoke(OrderMessage $message): void
    {
        $this->logger->notice('Отправляем уведомление');

        /**
         * Новое событие заказа
         *
         * @var OrderEvent $OrderEvent
         */
        $OrderEvent = $this->entityManager->getRepository(OrderEvent::class)->find($message->getEvent());


        if(!$OrderEvent)
        {
            return;
        }

        $OrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($OrderDTO);

        /** Если статус не New «Новый»  */
        if(false === $OrderDTO->getStatus()->equals(OrderStatusNew::class))
        {
            return;
        }

        if(!$OrderDTO->getProfile())
        {
            return;
        }

        $this->handle($message->getId());

    }

    public function handle(OrderUid $order): void
    {

        /** Получаем всех пользователей */


        return;


        /** Получаем всех Telegram пользователей, имеющих доступ к профилю заявки */
        $accounts = $this->accountTelegramRole->fetchAll($profile, 'ROLE_ORDERS');

        if(empty($accounts))
        {
            $this->logger->notice('Нет зарегистрированных профилей Telegram для отправки уведомления');
            return;
        }

        $detailOrder = $this->orderDetail->fetchDetailOrderAssociative($order);

        //$OrderDTO->getUsr()->getUsr()

        $menu[] = [
            'text' => '❌', // Удалить сообщение
            'callback_data' => 'telegram-delete-message'
        ];

        //$detailOrder['delivery_geocode_latitude']
        //$detailOrder['delivery_geocode_longitude']

//        $menu[] = [
        //            'text' => 'На карте',
        //            'callback_data' => 'telegram-delete-message' // telegram-location|latitude|longitude
        //        ];

        //        $menu[] = [
        //            'text' => '📦 Начать упаковку',
        //            'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile
        //        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ], JSON_THROW_ON_ERROR);

        $msg = '📦 <b>Поступил новый заказ</b>'.PHP_EOL;
        $msg .= sprintf('Номер: <b>%s</b>', $detailOrder['order_number']).PHP_EOL;
        $msg .= PHP_EOL;

        $msg .= '<b>Клиент</b>'.PHP_EOL;
        $msg .= PHP_EOL;

        try
        {
            $users = json_decode($detailOrder['order_user'], true, 512, JSON_THROW_ON_ERROR);

            foreach($users as $user)
            {
                $msg .= $user['profile_name'].': <b>'.$user['profile_value'].'</b>'.PHP_EOL;
            }
        }
        catch(Exception)
        {

        }

        if($detailOrder['delivery_geocode_address'])
        {
            $msg .= PHP_EOL;
            $msg .= '<b>Адрес доставки</b>'.PHP_EOL;
            $msg .= $detailOrder['delivery_geocode_address'];
        }

        foreach($accounts as $account)
        {
            $this
                ->telegramSendMessage
                ->chanel($account['chat'])
                ->message($msg)
                ->markup($markup)
                ->send();
        }

        $this->logger->info('Отправили сообщение о новом заказе');

    }

}