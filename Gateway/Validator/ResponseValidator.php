<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Validator;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

class ResponseValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        $response = SubjectReader::readResponse($validationSubject);

        if (isset($response['error'])) {
            $description = $response['error']['description']
                ?? $response['error']['code']
                ?? 'Unknown gateway error';

            return $this->createResult(false, [__('Payment gateway error: %1', $description)]);
        }

        if (empty($response)) {
            return $this->createResult(false, [__('Empty response received from payment gateway.')]);
        }

        return $this->createResult(true);
    }
}
