<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use OmnisSolutio\PaymentGateway\Logger\Logger;

/**
 * Phase D, step 7a (CALLBACK path) — receives the payment result from the renderer.
 *
 * The checkout app iframe fires window.parent.postMessage({status, payment_id}, '*').
 * The renderer intercepts that postMessage and POSTs here with {payment_id}.
 *
 * There is NO HMAC signature from the iframe — the security model is:
 *   1. We accept any payment_id the browser sends.
 *   2. The capture gateway command re-fetches the payment from the API
 *      (GET /api/v1/payin/payment/{id}/status) — server to server.
 *   3. If the API doesn't recognise it, the command throws and we abort.
 *   4. Amount/currency validation happens inside the CaptureHandler (Phase H).
 * This matches the WP plugin's approach (the verification stub comment in process_payment).
 *
 * The webhook (Phase E) is the independent safety net for the same transition
 * and must handle races: it checks the order state before acting.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface         $request,
        private readonly CheckoutSession          $checkoutSession,
        private readonly JsonFactory              $resultJsonFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Logger                   $logger
    ) {}

    public function execute(): Json
    {
        $result    = $this->resultJsonFactory->create();
        $paymentId = trim((string) $this->request->getParam('payment_id'));

        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId() || $order->getPayment() === null) {
            return $this->fail($result, 400, __('No order was found for this session.'));
        }

        if ($paymentId === '') {
            return $this->fail($result, 400, __('No payment ID received.'));
        }

        // Idempotent: the webhook may have already completed this order.
        if ($this->isAlreadyPaid($order)) {
            return $result->setData(['success' => true]);
        }

        try {
            $payment = $order->getPayment();
            $payment->setAdditionalInformation('omnissolutio_payment_id', $paymentId);
            $payment->setTransactionId($paymentId);

            // Runs the Phase B capture command:
            //   GET /api/v1/payin/payment/{id}/status  (server-to-server re-verification)
            //   → CaptureHandler stores status/method, sets last_trans_id
            //   → Magento creates the invoice and moves order to processing
            $payment->capture(null);

            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->error('OmnisSolutio callback capture failed', [
                'order_increment_id' => $order->getIncrementId(),
                'error'              => $e->getMessage(),
            ]);

            return $this->fail(
                $result,
                502,
                __('We could not confirm your payment. '
                    . 'If you were charged it will be reconciled automatically.')
            );
        }

        $this->logger->info('OmnisSolutio callback confirmed order', [
            'order_increment_id' => $order->getIncrementId(),
            'payment_id'         => $paymentId,
        ]);

        return $result->setData(['success' => true]);
    }

    private function isAlreadyPaid(OrderInterface $order): bool
    {
        return in_array(
            $order->getState(),
            [Order::STATE_PROCESSING, Order::STATE_COMPLETE],
            true
        );
    }

    private function fail(Json $result, int $code, \Magento\Framework\Phrase $message): Json
    {
        return $result->setHttpResponseCode($code)->setData([
            'success' => false,
            'message' => (string) $message,
        ]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Authenticated by the server-side payment re-verification inside capture(),
     * not by an explicit HMAC (the iframe posts no signature).
     * Phase H checklist item: "callback protected by form key or signature".
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
