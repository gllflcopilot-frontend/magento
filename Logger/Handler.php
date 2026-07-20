<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $loggerType = Logger::DEBUG;
    protected $fileName   = '/var/log/omnissolutio.log';
}
