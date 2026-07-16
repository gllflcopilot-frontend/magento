<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PaymentAction implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'authorize', 'label' => __('Authorize Only')],
            ['value' => 'authorize_capture', 'label' => __('Authorize and Capture')],
        ];
    }
}
