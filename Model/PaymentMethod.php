<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model;

/**
 * Holds the method code constant for use across the module.
 * The actual payment method is wired as a virtual-type Facade in di.xml.
 */
class PaymentMethod
{
    public const CODE = 'omnissolutio_gateway';
}
