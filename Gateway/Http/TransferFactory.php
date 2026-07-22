<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use OmnisSolutio\PaymentGateway\Model\Config;

class TransferFactory implements TransferFactoryInterface
{
    public function __construct(
        private readonly TransferBuilder $transferBuilder,
        private readonly Config          $config
    ) {}

    public function create(array $request): TransferInterface
    {
        return $this->transferBuilder
            ->setMethod($request['method'])
            ->setUri($this->config->getApiBaseUrl() . $request['endpoint'])
            ->setBody($request['body'] ?? [])
            ->setHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-TenantID'   => $this->config->getTenantId(),
            ])
            ->build();
    }
}
