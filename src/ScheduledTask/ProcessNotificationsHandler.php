<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask;

use Adyen\AdyenException;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Util\Currency;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Notification;
use Adyen\Webhook\PaymentStates;
use Adyen\Webhook\Processor\ProcessorFactory;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class ProcessNotificationsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    const WEBHOOK_TRANSACTION_STATES = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_AUTHORIZED,
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_PARTIALLY_PAID,
        OrderTransactionStates::STATE_REFUNDED,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
    ];

    private const MAX_ERROR_COUNT = 3;

    /**
     * @var NotificationService
     */
    private $notificationService;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;
    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;
    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;
    /**
     * @var array|null
     */
    private $adyenPaymentMethodIds = null;

    /** @var RefundService  */
    private $refundService;

    /** @var OrderTransactionRepository */
    private $orderTransactionRepository;

    /** @var CaptureService */
    private $captureService;

    /** @var array Mapping to convert Shopware transaction states to payment states in the webhook module. */
    private $webhookModuleStateMapping = [
        OrderTransactionStates::STATE_OPEN => PaymentStates::STATE_NEW,
        OrderTransactionStates::STATE_AUTHORIZED => PaymentStates::STATE_PENDING,
        OrderTransactionStates::STATE_PAID => PaymentStates::STATE_PAID,
        OrderTransactionStates::STATE_FAILED => PaymentStates::STATE_FAILED,
        OrderTransactionStates::STATE_IN_PROGRESS => PaymentStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_REFUNDED => PaymentStates::STATE_REFUNDED,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED => PaymentStates::STATE_PARTIALLY_REFUNDED,
    ];

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $paymentMethodRepository,
        AdyenPluginProvider $adyenPluginProvider,
        RefundService $refundService,
        OrderTransactionRepository $orderTransactionRepository,
        CaptureService $captureService
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->refundService = $refundService;
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->captureService = $captureService;
    }

    public static function getHandledMessages(): iterable
    {
        return [ ProcessNotifications::class ];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $notifications = $this->notificationService->getScheduledUnprocessedNotifications();

        foreach ($notifications->getElements() as $notification) {
            /** @var NotificationEntity $notification */

            $this->markAsProcessing($notification->getId());

            $order = $this->orderRepository->getOrderByOrderNumber(
                $notification->getMerchantReference(),
                $context,
                ['transactions', 'currency']
            );

            $logContext = ['eventCode' => $notification->getEventCode()];
            if (!$order) {
                $this->logger->warning(
                    "Order with order_number {$notification->getMerchantReference()} not found.",
                    $logContext
                );
                $this->markAsDone($notification->getId());
                continue;
            }
            $logContext['orderId'] = $order->getId();
            $logContext['orderNumber'] = $order->getOrderNumber();

            $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
                $order->getId(),
                self::WEBHOOK_TRANSACTION_STATES
            );

            // Skip if orderTransaction not found (non-Adyen)
            if (is_null($orderTransaction)) {
                $this->logger->error(
                    sprintf('Unable to identify Adyen orderTransaction linked to order %s', $order->getOrderNumber())
                );
                $this->markAsDone($notification->getId());
                continue;
            }

            $currentTransactionState = $this->webhookModuleStateMapping[
                $orderTransaction->getStateMachineState()->getTechnicalName()
            ] ?? '';

            if (empty($currentTransactionState)) {
                $logContext['paymentState'] = $orderTransaction->getStateMachineState()->getTechnicalName();
                $this->logger->error('Current payment state is not supported.', $logContext);
                $this->markAsDone($notification->getId());
                continue;
            }

            try {
                $notificationItem = Notification::createItem([
                    'eventCode' => $notification->getEventCode(),
                    'success' => $notification->isSuccess()
                ]);

                $processor = ProcessorFactory::create(
                    $notificationItem,
                    $currentTransactionState,
                    $this->logger
                );
            } catch (InvalidDataException $exception) {
                $logContext['notification'] = $notification->getVars();
                $this->logger->error('Unable to process notification: Invalid notification data', $logContext);
                $this->markAsDone($notification->getId());
                continue;
            }

            $state = $processor->process();

            try {
                if ($state !== $currentTransactionState) {
                    $this->transitionToState($notification, $orderTransaction, $state, $context);
                }

                if (!$notification->isSuccess()) {
                    $this->handleFailedNotification($notification, $order, $state);
                }

                $this->handleEventCodes($notification, $orderTransaction, $context);
            } catch (CaptureException $e) {
                $this->logger->warning($e->getMessage(), ['code' => $e->getCode()]);

                $scheduledProcessingTime = $this->captureService->getRescheduleNotificationTime();
                $this->rescheduleNotification($notification->getId(), $scheduledProcessingTime);
                continue;
            } catch (\Exception $exception) {
                $logContext['errorMessage'] = $exception->getMessage();
                // set notification error and increment error count
                $errorCount = (int)$notification->getErrorCount();
                $this->notificationService
                    ->saveError($notification->getId(), $exception->getMessage(), ++$errorCount);
                $this->logger->error('Notification processing failed.', $logContext);

                if ($errorCount < self::MAX_ERROR_COUNT) {
                    $this->rescheduleNotification($notification->getId());
                }

                continue;
            }

            $this->markAsDone($notification->getId());
        }

        $this->logger->info('Processed ' . $notifications->count() . ' notifications.');
    }

    /**
     * @param NotificationEntity $notification
     * @param OrderTransactionEntity $orderTransaction
     * @param string $state
     * @param Context $context
     * @throws AdyenException|CaptureException
     */
    private function transitionToState(
        NotificationEntity $notification,
        OrderTransactionEntity $orderTransaction,
        string $state,
        Context $context
    ) {
        $order = $orderTransaction->getOrder();

        switch ($state) {
            case PaymentStates::STATE_PAID:
                // The webhook processor returns 'PAID' for both AUTHORISATION and CAPTURE event codes.
                // For AUTHORISATION, set Order Transaction to `authorised` if manual capture is required.
                // Send capture request for open invoice payments.
                $paymentMethodHandler = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();
                if (EventCodes::AUTHORISATION === $notification->getEventCode() &&
                    $this->captureService->requiresManualCapture($paymentMethodHandler)) {
                    $this->logger->info(
                        'Manual capture required. Setting payment to `authorised` state.',
                        ['notification' => $notification->getVars()]
                    );
                    $this->transactionStateHandler->authorize($orderTransaction->getId(), $context);

                    $this->logger->info(
                        'Attempting capture for open invoice payment.',
                        ['notification' => $notification->getVars()]
                    );
                    $this->captureService->doOpenInvoiceCapture(
                        $notification->getMerchantReference(),
                        $notification->getAmountValue(),
                        $context
                    );
                } else {
                    $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
                }
                break;
            case PaymentStates::STATE_FAILED:
                $this->transactionStateHandler->fail($orderTransaction->getId(), $context);
                break;
            case PaymentStates::STATE_IN_PROGRESS:
                $this->transactionStateHandler->process($orderTransaction->getId(), $context);
                break;
            case PaymentStates::STATE_REFUNDED:
                // Determine whether refund was full or partial.
                $refundedAmount = (int) $notification->getAmountValue();

                $currencyUtil = new Currency();
                $totalPrice = $orderTransaction->getAmount()->getTotalPrice();
                $isoCode = $order->getCurrency()->getIsoCode();
                $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

                if ($refundedAmount > $transactionAmount) {
                    throw new \Exception('The refunded amount is greater than the transaction amount.');
                }

                $this->refundService->handleRefundNotification($order, $notification, RefundEntity::STATUS_SUCCESS);
                $transitionState = $refundedAmount < $transactionAmount
                    ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
                    : OrderTransactionStates::STATE_REFUNDED;

                $this->refundService->doRefund($orderTransaction, $transitionState, $context);

                break;
            default:
                break;
        }
    }

    private function handleEventCodes(
        NotificationEntity $notification,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ) {
        $order = $orderTransaction->getOrder();
        switch ($notification->getEventCode()) {
            case EventCodes::REFUND_FAILED:
                $this->logger->info(sprintf('Handling REFUND_FAILED on order: %s', $order->getOrderNumber()));
                $this->refundService->handleRefundNotification($order, $notification, RefundEntity::STATUS_FAILED);
                break;
            case EventCodes::CAPTURE:
                $this->logger->info(
                    'Handling CAPTURE notification',
                    ['order' => $order->getVars(), 'notification' => $notification->getVars()]
                );
                if ($notification->isSuccess()) {
                    $this->captureService->handleCaptureNotification(
                        $orderTransaction,
                        $notification,
                        PaymentCaptureEntity::STATUS_SUCCESS,
                        $context
                    );
                } else {
                    $this->captureService->handleCaptureNotification(
                        $orderTransaction,
                        $notification,
                        PaymentCaptureEntity::STATUS_FAILED,
                        $context
                    );
                }
                break;
            case EventCodes::CAPTURE_FAILED:
                $this->captureService->handleCaptureNotification(
                    $orderTransaction,
                    $notification,
                    PaymentCaptureEntity::STATUS_FAILED,
                    $context
                );
                break;
        }
    }

    private function markAsProcessing(string $notificationId)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', true);
        $this->logger->debug("Payment notification {$notificationId} marked as processing.");
    }

    private function rescheduleNotification(string $notificationId, ?\DateTime $dateTime = null)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->setNotificationSchedule($notificationId, $dateTime ?? new \DateTime());
        $this->logger->debug("Payment notification {$notificationId} rescheduled.");
    }

    private function markAsDone(string $notificationId)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->changeNotificationState($notificationId, 'done', true);
        $this->logger->debug("Payment notification {$notificationId} marked as done.");
    }

    /**
     * Handle logic to be executed when a notification has failed in this function
     *
     * @param NotificationEntity $notification
     * @param OrderEntity $order
     * @param string $state
     * @return OrderEntity
     * @throws AdyenException
     */
    private function handleFailedNotification(
        NotificationEntity $notification,
        OrderEntity $order,
        string $state
    ) {
        switch ($state) {
            case PaymentStates::STATE_PAID:
                // If for a refund, notification processor returns PAID, it means that the refund was not successful.
                // Hence, set the adyen_refund entity to failed
                if ($notification->getEventCode() === EventCodes::REFUND) {
                    $this->refundService->handleRefundNotification($order, $notification, RefundEntity::STATUS_FAILED);
                }

                break;
            default:
                break;
        }

        return $order;
    }
}
