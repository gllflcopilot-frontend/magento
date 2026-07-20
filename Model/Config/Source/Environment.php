<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public const SANDBOX = 'sandbox';
    public const LIVE = 'live';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox')],
            ['value' => self::LIVE, 'label' => __('Live')],
        ];
    }
}
