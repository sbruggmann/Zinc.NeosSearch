<?php
namespace Zinc\NeosSearch\Traits;

use Neos\Flow\Annotations as Flow;
use GuzzleHttp\Client;

trait ExecTrait
{

    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $zincSettings = [];

    /**
     * @param string[] $zincSettings
     */
    public function setZincSettings(array $zincSettings): void
    {
        $this->zincSettings = $zincSettings;
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param mixed $payload
     * @return false|string|null
     */
    private function exec($endpoint, $method = 'GET', $payload = null, $type = 'api')
    {
        $uri = sprintf('%s://%s:%s/%s/%s',
            $this->zincSettings['schema'],
            $this->zincSettings['hostname'],
            $this->zincSettings['port'],
            $type,
            $endpoint
        );

        $options = [
            'auth' => [$this->zincSettings['username'], $this->zincSettings['password']],
        ];

        if ($payload) {
            if (is_array($payload)) {
                $options['body'] = json_encode($payload);
            } elseif (is_string($payload)) {
                $options['body'] = $payload;
            }
        }

        $client = new Client();
        $res = $client->request($method, $uri, $options);

        if ($res->getStatusCode() !== 200) {
            return false;
        }

        return $res->getBody()->getContents();
    }

}
