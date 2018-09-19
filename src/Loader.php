<?php
namespace Lockr;

use Exception;

use GuzzleHttp;
use GuzzleHttp\Psr7;
use Psr\Http\Message\ResponseInterface;

use Lockr\Exception\LockrClientException;
use Lockr\Exception\LockrServerException;
use Lockr\Model;

class Loader implements LoaderInterface
{
    /** @var GuzzleHttp\ClientInterface $httpClient */
    private $httpClient;

    /** @var array $cache */
    private $cache = [];

    /** @var array $modelMap */
    private $modelMap = [
        'cert' => Model\Cert::class,
        'client' => Model\Client::class,
        'client-token' => Model\ClientToken::class,
        'secret' => Model\Secret::class,
        'secret-value' => Model\SecretValue::class,
        'site' => Model\Site::class,
    ];

    /** @var array $routeMap */
    private $routeMap = [
        'cert' => 'certs',
        'client' => 'clients',
        'client-token' => 'client-tokens',
        'secret' => 'secrets',
        'secret-value' => 'secret-values',
        'site' => 'sites',
    ];

    /**
     * @param GuzzleHttp\ClientInterface $http_client
     */
    public function __construct(GuzzleHttp\ClientInterface $http_client)
    {
        $this->httpClient = $http_client;
    }

    /**
     * @param SettingsInterface $settings
     */
    public static function createFromSettings(SettingsInterface $settings)
    {
        $ua = 'php/' . phpversion() . ' LockrClient/' . self::VERSION;
        $handler = GuzzleHttp\HandlerStack::create();
        $handler->push(MiddlewareFactory::retry());
        $base_options = [
            'base_uri' => 'https://{$settings->getHostname()}',
            'handler' => $handler,
            'connect_timeout' => 2.0,
            'expect' => false,
            'headers' => [
                'accept' => ['application/api+json'],
                'user-agent' => [$ua],
            ],
            'http_errors' => false,
            'read_timeout' => 3.0,
            'timeout' => 5.0,
        ];
        $options = array_replace($base_options, $settings->getOptions());
        $client = new GuzzleHttp\Client($options);
        return new static($client);
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data)
    {
        $uri = "/{$this->routeMap[$data['type']]}";
        $headers = [
            'content-type' => ['application/api+json'],
        ];
        $body = json_encode(['data' => $data]);
        $req = new Psr7\Request('POST', $uri, $headers, $body);
        $resp = $this->httpClient->send($req);
        $this->checkErrors($resp);
        $body = json_decode((string) $resp->getBody(), true);
        return $this->loadBody($body);
    }

    /**
     * {@inheritdoc}
     */
    public function load($type, $id, array $include = null)
    {
        $key = "{$type}:{$id}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $uri = "/{$this->routeMap[$type]}/{$id}";
        if ($include) {
            $uri .= '?include=' . urlencode(implode(',', $include));
        }
        $req = new Psr7\Request('GET', $uri);
        $resp = $this->httpClient->send($req);
        $this->checkErrors($resp);
        $body = json_decode((string) $resp->getBody(), true);
        return $this->loadBody($body);
    }

    /**
     * Loads a collection.
     *
     * @param string $type
     *
     * @return ModelInterface[]
     */
    public function loadCollection($type)
    {
        $uri = "/{$this->routeMap[$type]}";
        $req = new Psr7\Request('GET', $uri);
        $resp = $this->httpClient->send($req);
        $this->checkErrors($resp);
        $body = json_decode((string) $resp->getBody(), true);
        return array_map([$this, 'loadModel'], $body['data']);
    }

    /**
     * {@inheritdoc}
     */
    public function loadRelated(ModelInterface $model, $name)
    {
        $rel = $model->getRelationship($name);
        if (isset($rel['data'])) {
            $data = $rel['data'];
            return $this->load($data['type'], $data['id']);
        }
        $links = $rel['links'];
        $uri = $links['related'];
        $req = new Psr7\Request('GET', $uri);
        $resp = $this->httpClient->send($req);
        $this->checkErrors($resp);
        $body = json_decode((string) $resp->getBody(), true);
        return $this->loadBody($body);
    }

    /**
     * Loads the primary data model, and caches included models.
     *
     * @param array $body
     *
     * @return ModelInterface
     */
    private function loadBody(array $body)
    {
        if (isset($body['included'])) {
            foreach ($body['included'] as $data) {
                $this->loadModel($data);
            }
        }

        return $this->loadModel($body['data']);
    }

    /**
     * @param array $data
     *
     * @return ModelInterface
     */
    private function loadModel(array $data)
    {
        $type = $data['type'];
        $cls = $this->modelMap[$type];
        $model = new $cls($data);
        $this->cache["{$type}:{$data['id']}"] = $model;
        return $model;
    }

    /**
     * @param ResponseInterface
     */
    private function checkErrors(ResponseInterface $resp)
    {
        $status = $resp->getStatusCode();
        if ($status >= 400) {
            $errors = [];
            if ($body = json_decode((string) $resp->getBody(), true) &&
                isset($body['errors'])
            ) {
                foreach ($body['errors'] as $error) {
                    $errors[] = new ApiError($error);
                }
            }
            if ($status >= 500) {
                $e = new LockrServerException($errors);
            } else {
                $e = new LockrClientException($errors);
            }
            throw $e;
        }
    }
}

// ex: ts=4 sts=4 sw=4 et:
