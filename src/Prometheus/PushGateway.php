<?php
declare(strict_types = 1);

namespace Prometheus;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class PushGateway
{
    const ALLOWED_TRANSPORT_METHODS = [
        'http',
        'https',
    ];

    /**
     * @var string
     */
    private $address;

    /**
     * @var string
     */
    private $transport;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * PushGateway constructor.
     * @param $address string host:port of the push gateway
     * @param $transport string transport method of the push gateway
     * @param string $address host:port of the push gateway
     * @param ClientInterface $client
     */
    public function __construct($address, ClientInterface $client = null, $transport = 'http')
    {
        $this->address = $address;
        $this->client = $client ?? new Client();

        if (!in_array($transport, self::ALLOWED_TRANSPORT_METHODS)) {
            throw new \InvalidArgumentException(\sprintf('Invalid transport "%s"', $transport));
        }

        $this->transport = $transport;
    }

    /**
     * Pushes all metrics in a Collector, replacing all those with the same job.
     * Uses HTTP PUT.
     * @param CollectorRegistry $collectorRegistry
     * @param string $job
     * @param array $groupingKey
     * @throws GuzzleException
     */
    public function push(CollectorRegistry $collectorRegistry, string $job, array $groupingKey = []): void
    {
        $this->doRequest($collectorRegistry, $job, 'put', $groupingKey);
    }

    /**
     * Pushes all metrics in a Collector, replacing only previously pushed metrics of the same name and job.
     * Uses HTTP POST.
     * @param CollectorRegistry $collectorRegistry
     * @param $job
     * @param $groupingKey
     * @throws GuzzleException
     */
    public function pushAdd(CollectorRegistry $collectorRegistry, string $job, array $groupingKey = []): void
    {
        $this->doRequest($collectorRegistry, $job, 'post', $groupingKey);
    }

    /**
     * Deletes metrics from the Push Gateway.
     * Uses HTTP POST.
     * @param string $job
     * @param array $groupingKey
     * @throws GuzzleException
     */
    public function delete(string $job, array $groupingKey = []): void
    {
        $this->doRequest(null, $job, 'delete', $groupingKey);
    }

    /**
     * @param CollectorRegistry $collectorRegistry
     * @param string $job
     * @param array $groupingKey
     * @param string $method
     * @throws GuzzleException
     */
    private function doRequest(CollectorRegistry $collectorRegistry, string $job, $method, array $groupingKey = null): void
    {
        $url = \sprintf(
            "%s://%s/metrics/job/%s",
            $this->transport,
            $this->address,
            $job
        );

        if (!empty($groupingKey)) {
            foreach ($groupingKey as $label => $value) {
                $url .= "/" . $label . "/" . $value;
            }
        }

        $requestOptions = [
            'headers' => [
                'Content-Type' => RenderTextFormat::MIME_TYPE,
            ],
            'connect_timeout' => 10,
            'timeout' => 20,
        ];

        if ($method != 'delete') {
            $renderer = new RenderTextFormat();
            $requestOptions['body'] = $renderer->render($collectorRegistry->getMetricFamilySamples());
        }
        $response = $this->client->request($method, $url, $requestOptions);
        $statusCode = $response->getStatusCode();
        if (!in_array($statusCode, [200, 202])) {
            $msg = "Unexpected status code "
                . $statusCode
                . " received from push gateway "
                . $this->address . ": " . $response->getBody();
            throw new RuntimeException($msg);
        }
    }
}
