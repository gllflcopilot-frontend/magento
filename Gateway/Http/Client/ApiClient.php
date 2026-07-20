<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use OmnisSolutio\PaymentGateway\Model\Config;
use Psr\Log\LoggerInterface;

class ApiClient implements ClientInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {}

    public function placeRequest(TransferInterface $transferObject): array
    {
        $uri    = $transferObject->getUri();
        $method = $transferObject->getMethod();
        $body   = $transferObject->getBody();

        try {
            $this->curl->setHeaders($transferObject->getHeaders());
            $this->curl->setCredentials(
                $transferObject->getAuthUsername(),
                $transferObject->getAuthPassword()
            );
            $this->curl->setTimeout(10);

            if ($method === 'GET') {
                $this->curl->get($uri);
            } elseif ($method === 'POST') {
                $this->curl->post($uri, $this->json->serialize($body));
            } else {
                throw new ClientException(__('Unsupported HTTP method: %1', $method));
            }

            $response = $this->json->unserialize($this->curl->getBody());
        } catch (ClientException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ClientException(__($e->getMessage()), $e);
        }

        if ($this->config->isDebug()) {
            $this->logger->debug('OmnisSolutio API', [
                'method' => $method,
                'uri'    => $uri,
                'status' => $this->curl->getStatus(),
            ]);
        }

        return is_array($response) ? $response : [];
    }
}
