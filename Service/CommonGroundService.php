<?php

// Conduction/CommonGroundBundle/Service/CommonGroundService.php

namespace Conduction\CommonGroundBundle\Service;

use Conduction\CommonGroundBundle\Event\CommonGroundEvents;
use Conduction\CommonGroundBundle\Event\CommongroundUpdateEvent;
use DateInterval;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
// Events
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class CommonGroundService
{
    /**
     * @var ParameterBagInterface
     */
    private $params;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var SessionInterface
     */
    private $session;

    private $headers;

    /**
     * @var RequestStack
     */
    private $requestStack;

    private $request;

    /**
     * @var FlashBagInterface
     */
    private $flash;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var string
     */
    private $local;

    public function __construct(
        ParameterBagInterface $params,
        SessionInterface $session,
        CacheInterface $cache,
        RequestStack $requestStack,
        FlashBagInterface $flash,
        TranslatorInterface $translator,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->params = $params;
        $this->session = $session;
        $this->cache = $cache;
        $this->session = $session;
        $this->requestStack = $requestStack;
        $this->flash = $flash;
        $this->translator = $translator;
        $this->eventDispatcher = $eventDispatcher;

        $this->request = $this->requestStack->getCurrentRequest();

        // To work with NLX we need a couple of default headers
        $this->headers = [
            'Accept'        => 'application/ld+json',
            'Content-Type'  => 'application/json',
            'Authorization' => $this->params->get('app_application_key'),
            // NL Api Strategie
            'Accept-Crs'  => 'EPSG:4326',
            'Content-Crs' => 'EPSG:4326',
        ];

        if ($session->get('user') && is_array($session->get('user')) && array_key_exists('@id', $session->get('user'))) {
            $headers['X-NLX-Request-User-Id'] = $session->get('user')['@id'];
        } elseif ($session->get('user')) {
            $headers['X-NLX-Request-User-Id'] = $session->get('user');
        }

        if ($session->get('process')) {
            $headers[] = $session->get('process')['@id'];
        }

        // We might want to overwrite the guzle config, so we declare it as a separate array that we can then later adjust, merge or otherwise influence
        $this->guzzleConfig = [
            // Base URI is used with relative requests
            'http_errors' => false,
            // You can set any number of default request options.
            'timeout' => 4000.0,
            // To work with NLX we need a couple of default headers
            'headers' => $this->headers,
            // Do not check certificates
            'verify' => false,
        ];

        if ($this->params->has('app_certificate') && file_exists($this->params->get('app_certificate'))) {
            $this->guzzleConfig['cert'] = $this->params->get('app_certificate');
        }
        if ($this->params->has('app_ssl_key') && file_exists($this->params->get('app_ssl_key'))) {
            $this->guzzleConfig['ssl_key'] = $this->params->get('app_ssl_key');
        }

        // Lets start up a default client
        $this->client = new Client($this->guzzleConfig);

        // Locale
        if (!empty($this->request)) {
            $this->local = $this->request->getLocale();
        }
    }

    public function isCommonGround(string $url)
    {
        $parsedUrl = parse_url($url);
        $returnUrl = [];

        if (!key_exists('scheme', $parsedUrl) || !key_exists('host', $parsedUrl)) {
            return false;
        }

        $path = explode('/', $parsedUrl['path']);
        $host = explode('.', $parsedUrl['host']);

        /**@TODO: Dit moet echt nog even wat dynamischer */
        if (in_array('api', $path) && count($path) > 3) {
            $componentPath = implode('/', array_slice($path, 0, 4));

            $path = array_splice($path, 4);

            $returnUrl['id'] = implode('/', array_splice($path, 1));
            $returnUrl['type'] = implode('', $path);
        } else {
            $componentPath = '';

            $returnUrl['id'] = implode('/', array_splice($path, 2));
            $returnUrl['type'] = implode('', $path);
        }

        $componentUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}{$componentPath}";
        $components = $this->params->get('common_ground.components');
        foreach ($components as $code => $component) {
            if ($component['location'] == $componentUrl || strpos($component['location'], $componentUrl, ) !== false) {
                $returnUrl['component'] = $code;

                return $returnUrl;
            }
        }
        if (count($path) > 1 && $this->getComponentHealth($path[1])) {
            $returnUrl['component'] = end($path);

            return $returnUrl;
        } elseif ($this->getComponentHealth($host[0])) {
            $returnUrl['component'] = $host[0];

            return $returnUrl;
        }

        return false;
    }

    /*
     * Get a list of resources from a commonground component
     *
     * $endpoint array Eather an endpoint in the form of an url or and component as array to wisch to post
     * $query array optional query paramaters
     * $cache boolean Check cache before making an api call
     * $async boolean determens whether to fire the api cal asynchronus
     * $autowire boolean whether autowire the url
     * $events boolean whether to trigger event hooks
     * $error boolean whether to throw an exeption on errors
     *
     * return Object A list datatransfer object
     */
    public function getResourceList($endpoint, $query = [], $cache = true, $async = false, $autowire = true, $events = true, $error = true)
    {
        if (is_array($endpoint) && array_key_exists('component', $endpoint)) {
            $component = $this->getComponent($endpoint['component']);
            if (array_key_exists('accept', $endpoint)) {
                $component['accept'] = $endpoint['accept'];
            }
        } else {
            if (!is_array($endpoint) && $endpoint != null && $componentUrl = $this->isCommonGround($endpoint)) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } else {
                $component = [];
            }
        }

        $url = $this->cleanUrl($endpoint, false, $autowire);

        $item = $this->cache->getItem('commonground_'.md5($url).'_'.$this->local);
        if ($item->isHit() && $cache && $this->params->get('app_cache')) {
            // return $item->get();
        }

        // To work with NLX we need a couple of default headers
        $auth = false;
        $headers = $this->headers;

        /*
         * Pagination might have been aplied, if so we would like to pass that trough
         */
        if ($this->request instanceof Request) {
            if ($start = $this->request->query->get('start')) {
                $query['start'] = (int) $start;
            }
            if ($limit = $this->request->query->get('limit')) {
                $query['limit'] = (int) $limit;
            }
        }

        $query = $this->convertQuery($query);

        // Component specific congiguration
        if ($component && array_key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        }
        if ($component && array_key_exists('auth', $component)) {
            switch ($component['auth']) {
                case 'jwt':
                    $headers['Authorization'] = 'Bearer '.$this->getJwtToken($component['code']);
                    break;
                case 'username-password':
                    $auth = [$component['username'], $component['password']];
            }
        }

        if (defined($this->request) && $this->request) {
            if ($start = $this->request->query->get('start')) {
                $query['start'] = (int) $start;
            }
            if ($limit = $this->request->query->get('limit')) {
                $query['limit'] = (int) $limit;
            }
        }

        if (!$async) {
            try {
                $response = $this->client->request('GET', $url, [
                    'query'       => $query,
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        } else {
            try {
                $response = $this->client->requestAsync('GET', $url, [
                    'query'       => $query,
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody()->getContents();
        $response = json_decode($body, true);

        // Fallback for non-json code
        if (!$response) {
            $response = $body;
            $item->set($response);
            $item->expiresAt(new \DateTime('tomorrow'));
            $this->cache->save($item);

            return $response;
        }

        // The trick here is that if statements are executed left to right. So the prosses errors wil only be called when all other conditions are met
        /* @todo 201 hier vewijderen is een hack */
        if ($statusCode != 200 && $statusCode != 201 && !$this->proccesErrors($response, $statusCode, $headers, null, $url, 'GET')) {
            return false;
        }

        $parsedUrl = parse_url($url);

        /* @todo this should look to al @id keus not just the main root */
        $response = $this->convertAtId($response, $parsedUrl);

        // plain json catch
        if (array_key_exists('results', $response)) {
            foreach ($response['results'] as $key => $value) {
                $response['results'][$key] = $this->enrichObject($value, $parsedUrl);
            }
        }

        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($response, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::LIST
            );
        }

        return $response;
    }

    /*
     * Get a single resource from a common ground componant
     *
     * param $endpoint array Eather an endpoint in the form of an url or and component as array to wisch to post
     * param $query array optional query paramaters
     * param $cache boolean Check cache before making an api call
     * param $async boolean determens whether to fire the api cal asynchronus
     * param $autowire boolean whether autowire the url
     * param $events boolean whether to trigger event hooks
     * param $error boolean whether to throw an exeption on errors
     *
     * return Array the found commonground resource
     */
    public function getResource($endpoint, $query = [], $cache = true, $async = false, $autowire = true, $events = true, $error = true)
    {
        if (is_array($endpoint) && array_key_exists('component', $endpoint)) {
            $component = $this->getComponent($endpoint['component']);
            if (array_key_exists('accept', $endpoint)) {
                $component['accept'] = $endpoint['accept'];
            }
        } else {
            if (!is_array($endpoint) && $endpoint != null && $componentUrl = $this->isCommonGround($endpoint)) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } else {
                $component = [];
            }
        }

        $url = $this->cleanUrl($endpoint, false, $autowire);

        $item = $this->cache->getItem('commonground_'.md5($url).'_'.$this->local);

        if ($item->isHit() && $cache && $this->params->get('app_cache')) {
            return $item->get();
        }

        // To work with NLX we need a couple of default headers
        $auth = false;
        $headers = $this->headers;
        $headers['X-NLX-Request-Subject-Identifier'] = $url;

        $query = $this->convertQuery($query);

        // Component specific congiguration
        if ($component && array_key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        }
        if ($component && array_key_exists('auth', $component)) {
            switch ($component['auth']) {
                case 'jwt-HS256':
                case 'jwt-RS512':
                case 'jwt':
                    $headers['Authorization'] = 'Bearer '.$this->getJwtToken($component['code']);
                    break;
                case 'username-password':
                    $auth = [$component['username'], $component['password']];
                    break;
                case 'apikey':
                default:
                    $headers['Authorization'] = $component['apikey'];
                    break;
            }
        }
        if (!$async) {
            try {
                $response = $this->client->request('GET', $url, [
                    'query'       => $query,
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        } else {
            try {
                $response = $this->client->requestAsync('GET', $url, [
                    'query'       => $query,
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $response = json_decode($body, true);

        // Fallback for non-json code
        if (!$response) {
            $response = $body;
            $item->set($response);
            $item->expiresAt(new \DateTime('tomorrow'));
            $this->cache->save($item);

            return $response;
        }

        // The trick here is that if statements are executed left to right. So the prosses errors wil only be called when all other conditions are met
        if ($statusCode != 200 && !$this->proccesErrors($response, $statusCode, $headers, null, $url, 'GET')) {
            return false;
        }

        $parsedUrl = parse_url($url);

        $response = $this->convertAtId($response, $parsedUrl);

        $response = $this->enrichObject($response, $parsedUrl);

        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($response, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::RESOURCE
            );
            $response = $event->getResource();
        }

        return $response;
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function updateResource($resource, $endpoint = null, $async = false, $autowire = true, $events = true, $error = true)
    {
        if (is_array($endpoint) && array_key_exists('component', $endpoint)) {
            $component = $this->getComponent($endpoint['component']);
        } else {
            if (!is_array($endpoint) && $endpoint != null && $componentUrl = $this->isCommonGround($endpoint)) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } elseif ($endpoint == null && array_key_exists('@id', $resource) && $componentUrl = $this->isCommonGround($resource['@id'])) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } else {
                $component = [];
            }
        }

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component, $endpoint);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::UPDATE
            );
            $resource = $event->getResource();
        }

        $url = $this->cleanUrl($endpoint, $resource, $autowire);

        // To work with NLX we need a couple of default headers
        $auth = false;
        $headers = $this->headers;
        $headers['X-NLX-Request-Subject-Identifier'] = $url;

        // Component specific congiguration
        if ($component && array_key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        }
        if ($component && array_key_exists('auth', $component)) {
            switch ($component['auth']) {
                case 'jwt-HS256':
                case 'jwt-RS512':
                case 'jwt':
                    $headers['Authorization'] = 'Bearer '.$this->getJwtToken($component['code']);
                    break;
                case 'username-password':
                    $auth = [$component['username'], $component['password']];
                    break;
                case 'apikey':
                default:
                    $headers['Authorization'] = $component['apikey'];
                    break;
            }
        }

        $resource = $this->cleanResource($resource);

        //Unset properties without values. To force empty, set an empty array ([])
        foreach ($resource as $key => $value) {
            if ($value === null) {
                unset($resource[$key]);
            }
        }

        if (!$async) {
            try {
                $response = $this->client->request('PUT', $url, [
                    'body'        => json_encode($resource),
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        } else {
            try {
                $response = $this->client->requestAsync('PUT', $url, [
                    'body'        => json_encode($resource),
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        }

        $statusCode = $response->getStatusCode();
        $response = json_decode($response->getBody(), true);

        // The trick here is that if statements are executed left to right. So the prosses errors wil only be called when all other conditions are met
        if ($statusCode != 200 && !$this->proccesErrors($response, $statusCode, $headers, $resource, $url, 'PUT')) {
            return false;
        }

        $parsedUrl = parse_url($url);

        $response = $this->convertAtId($response, $parsedUrl);

        $response = $this->enrichObject($response, $parsedUrl);

        // Lets cache this item for speed purposes
        $item = $this->cache->getItem('commonground_'.md5($url).'_'.$this->local);
        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($response, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::UPDATED
            );
        }

        return $response;
    }

    /*
     * Create a sresource on a common ground component
     */
    public function createResource($resource, $endpoint = null, $async = false, $autowire = true, $events = true, $error = true)
    {
        if (is_array($endpoint) && array_key_exists('component', $endpoint)) {
            $component = $this->getComponent($endpoint['component']);
        } else {
            if (!is_array($endpoint) && $endpoint != null && $componentUrl = $this->isCommonGround($endpoint)) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } elseif ($endpoint == null && array_key_exists('@id', $resource) && $componentUrl = $this->isCommonGround($resource['@id'])) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } else {
                $component = [];
            }
        }

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component, $endpoint);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::CREATE
            );
            $resource = $event->getResource();
        }

        $url = $this->cleanUrl($endpoint, $resource, $autowire);

        // Set headers
        $auth = false;
        $headers = $this->headers;

        // Component specific congiguration
        if ($component && array_key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        }
        if ($component && array_key_exists('auth', $component)) {
            switch ($component['auth']) {
                case 'jwt-HS256':
                case 'jwt-RS512':
                case 'jwt':
                    $headers['Authorization'] = 'Bearer '.$this->getJwtToken($component['code']);
                    break;
                case 'username-password':
                    $auth = [$component['username'], $component['password']];
                    break;
                case 'apikey':
                default:
                    $headers['Authorization'] = $component['apikey'];
                    break;
            }
        }

        $resource = $this->cleanResource($resource);

        if (!$async) {
            try {
                $response = $this->client->request('POST', $url, [
                    'body'        => json_encode($resource),
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        } else {
            try {
                $response = $this->client->requestAsync('POST', $url, [
                    'body'        => json_encode($resource),
                    'headers'     => $headers,
                    'auth'        => $auth,
                    'http_errors' => $error,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents());
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        }

        $statusCode = $response->getStatusCode();
        $response = json_decode($response->getBody(), true);

        // The trick here is that if statements are executed left to right. So the prosses errors wil only be called when all other conditions are met
        if ($statusCode != 201 && $statusCode != 200 && !$this->proccesErrors($response, $statusCode, $headers, $resource, $url, 'POST')) {
            return false;
        }

        $parsedUrl = parse_url($url);

        $response = $this->convertAtId($response, $parsedUrl);

        $response = $this->enrichObject($response, $parsedUrl);

        // Lets cache this item for speed purposes
        $item = $this->cache->getItem('commonground_'.md5($url.'/'.$response['id']).'_'.$this->local);
        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($response, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::CREATED
            );
        }

        return $response;
    }

    /*
     * Delete a single resource from a common ground component
     */
    public function deleteResource($resource, $url = null, $async = false, $autowire = true, $events = true)
    {
        if (is_array($url) && array_key_exists('component', $url)) {
            $component = $this->getComponent($url['component']);
        } else {
            if (!is_array($url) && $url != null && $componentUrl = $this->isCommonGround($url)) {
                $url = $componentUrl;
                $component = $this->getComponent($url['component']);
                if (array_key_exists('accept', $url)) {
                    $component['accept'] = $url['accept'];
                }
            } elseif ($url == null && array_key_exists('@id', $resource) && $componentUrl = $this->isCommonGround($resource['@id'])) {
                $url = $componentUrl;
                $component = $this->getComponent($url['component']);
                if (array_key_exists('accept', $url)) {
                    $component['accept'] = $url['accept'];
                }
            } else {
                $component = [];
            }
        }

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component, $url);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::DELETE
            );
        }
        $url = $this->cleanUrl($url, $resource, $autowire);

        // Set headers
        $auth = false;
        $headers = $this->headers;

        // Component specific congiguration
        if ($component && array_key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        }
        if ($component && array_key_exists('auth', $component)) {
            switch ($component['auth']) {
                case 'jwt-HS256':
                case 'jwt-RS512':
                case 'jwt':
                    $headers['Authorization'] = 'Bearer '.$this->getJwtToken($component['code']);
                    break;
                case 'username-password':
                    $auth = [$component['username'], $component['password']];
                    break;
                case 'apikey':
                default:
                    $headers['Authorization'] = $component['apikey'];
                    break;
            }
        }

        if (!$async) {
            try {
                $response = $this->client->request('DELETE', $url, [
                    'headers' => $headers,
                    'auth'    => $auth,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        } else {
            try {
                $response = $this->client->requestAsync('DELETE', $url, [
                    'headers' => $headers,
                    'auth'    => $auth,
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // here's the good stuff
//                var_dump($e->getResponse()->getBody()->getContents()); //Log::error($e->getResponse()->getBody()->getContents());

                throw $e;
            }
        }

        $statusCode = $response->getStatusCode();
        $response = json_decode($response->getBody(), true);

        // The trick here is that if statements are executed left to right. So the prosses errors wil only be called when all other conditions are met
        if ($statusCode != 204 && !$this->proccesErrors($response, $statusCode, $headers, $resource, $url, 'DELETE')) {
            return false;
        }

        // Remove the item from cache
        $this->cache->delete('commonground_'.md5($url).'_'.$this->local);

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::DELETED
            );
        }

        return true;
    }

    /*
     * The save fucntion should only be used by applications that can render flashes
     */
    public function saveResource($resource, $endpoint = false, $autowire = true, $events = true, $succesMessages = true, $errorMessages = true)
    {
        // We dont require an endpoint if a resource is self explanatory
        if (is_array($endpoint) && array_key_exists('component', $endpoint)) {
            $component = $this->getComponent($endpoint['component']);
        } else {
            if (!is_array($endpoint) && $endpoint != null && $componentUrl = $this->isCommonGround($endpoint)) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } elseif ($endpoint == null && array_key_exists('@id', $resource) && $componentUrl = $this->isCommonGround($resource['@id'])) {
                $endpoint = $componentUrl;
                $component = $this->getComponent($endpoint['component']);
                if (array_key_exists('accept', $endpoint)) {
                    $component['accept'] = $endpoint['accept'];
                }
            } else {
                $component = [];
            }
        }

        // creates the ResourceUpdateEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component, $endpoint);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::SAVE
            );
            $resource = $event->getResource();
        }
        // determine the endpoint
        $endpoint = $this->cleanUrl($endpoint, $resource, $autowire);

        // @tododit zijn echt te veel ifjes

        // If the resource exists we are going to update it, if not we are going to create it
        if (array_key_exists('@id', $resource) && $resource['@id']) {
            if ($this->updateResource($resource, null, false, $autowire, $events)) {
                // Lets renew the resource
                $resource = $this->getResource($resource['@id'], [], false, false, $autowire, $events);
                if ($succesMessages) {
                    $this->throwMessage('success', $resource, 'saved');
                }
            } elseif (array_key_exists('name', $resource) && $errorMessages) {
                $this->throwMessage('error', $resource, 'could not be saved');
            }
        } else {
            if ($createdResource = $this->createResource($resource, $endpoint, false, $autowire, $events)) {
                // Lets renew the resource
                $resource = $this->getResource($createdResource['@id'], [], false, false, $autowire, $events);
                if ($succesMessages) {
                    $this->throwMessage('success', $resource, 'created');
                }
            } elseif ($errorMessages) {
                $this->throwMessage('error', $resource, 'could not be created');
            }
        }

        // creates the ResourceSavedEvent and dispatches it
        if ($events) {
            $event = new CommongroundUpdateEvent($resource, $component);
            $this->eventDispatcher->dispatch(
                $event,
                CommonGroundEvents::SAVED
            );
        }

        return $resource;
    }

    public function throwMessage($type = 'succes', $resource = [], $message)
    {
        $text = '';

        /* @todo deze willen we eigenlijk translaten */
        if (array_key_exists('@type', $resource)) {
            $resourceType = $resource['@type'];
            $resourceType = strtolower($resourceType);

            // The @type might be a schema org reference s
            if (filter_var($resourceType, FILTER_VALIDATE_URL)) {
                $resourceType = $this->getUuidFromUrl($resourceType);
            }

            $text = $text.$this->translator->trans($resourceType);
        }

        // Lets try to name the object
        if (array_key_exists('reference', $resource)) {
            $text = $text.' '.$resource['reference'];
        } elseif (array_key_exists('name', $resource)) {
            $text = $text.' '.$resource['name'];
        } elseif (array_key_exists('id', $resource)) {
            $text = $text.' '.$resource['id'];
        } else {
            /// do nothing
        }

        // set the message
        $text = $text.' '.$this->translator->trans($message);

        // Throw te actual flash
        $this->flash->add($type, $text);
    }

    public function isResource($url)
    {
        try {
            return $this->getResource($url);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /*
     * Get the current application from the wrc
     */
    public function getApplication($force = false, $async = false)
    {
        $application = $this->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => $this->params->get('app_id')]);

        return $application;
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function clearFromsCash($resource, $url = false)
    {
        $url = $this->cleanUrl($url, $resource);

        $this->cache->delete('commonground_'.md5($url));
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function cleanResource($resource)
    {
        unset($resource['@context']);
        unset($resource['@id']);
        unset($resource['@type']);
        unset($resource['id']);
        unset($resource['_links']);
        unset($resource['_embedded']);

        return $resource;
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function proccesErrors($response, $statusCode, $headers, $resource, $url, $proces)
    {
        // Non-Json suppor

        if (!$response) {
            $this->flash->add('error', $statusCode.':'.$url);
        } // ZGW support
        elseif (!array_key_exists('@type', $response) && array_key_exists('types', $response)) {
            $this->flash->add('error', $this->translator->trans($response['detail']));
        } // Hydra Support
        elseif (array_key_exists('@type', $response) && $response['@type'] == 'ConstraintViolationList') {
            foreach ($response['violations'] as $violation) {
                $this->flash->add('error', $violation['propertyPath'].' '.$this->translator->trans($violation['message']));
            }

            return false;
        } elseif (array_key_exists('hydra:description', $response)) {
            $this->flash->add('error', $this->translator->trans($response['hydra:description']));

            return false;
        } else {
            throw new HttpException($statusCode, $url.' returned: '.json_encode($response));
        }

        return $response;
    }

    /*
     * Turns plain json objects into ld+jsons
     */
    private function enrichObject(array $object, array $parsedUrl)
    {
        while (!array_key_exists('@id', $object)) {
            if (array_key_exists('url', $object)) {
                $object['@id'] = $object['url'];
                break;
            }

            // Lets see if the path ends in a UUID
            /*
    		$path_parts = pathinfo($parsedUrl["path"]);
    		$path_parts['dirname'];

    		if (is_string($path_parts['dirname']) && (preg_match('/^[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}$/i', $path_parts['dirname']) == 1)) {
    			$object['@id'] = implode($parsedUrl);
    			break;
    		}
    		*/
            break;
        }

        //while(!array_key_exists ('@type', $object)){
        //
        //}

        while (!array_key_exists('@self', $object)) {
            if (array_key_exists('@id', $object)) {
                $object['@self'] = $object['@id'];
                break;
            }
            if (array_key_exists('url', $object)) {
                $object['@self'] = $object['url'];
                break;
            }

            break;
        }

        while (!array_key_exists('id', $object)) {
            // Lets see if an UUID is provided
            if (array_key_exists('uuid', $object)) {
                $object['id'] = $object['uuid'];
                break;
            }

            // What if we dont have an id at all?
            if (!array_key_exists('@id', $object)) {
                break;
            }

            // Lets see if the path ends in a UUID
            $parsedId = parse_url($object['@id']);

            $path_parts = pathinfo($parsedId['path']);
            $path_parts['dirname'];

            //var_dump($path_parts);

            if (is_string($path_parts['basename']) && (preg_match('/^[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}$/i', $path_parts['basename']) == 1)) {
                $object['id'] = $path_parts['basename'];
                break;
            }
            //$object['id']=$path_parts['basename'];

            break;
        }

        while (!array_key_exists('name', $object)) {
            // ZGW specifiek
            if (array_key_exists('omschrijving', $object)) {
                $object['name'] = $object['omschrijving'];
                break;
            }

            if (array_key_exists('id', $object)) {
                // Fallbask set de id als naams
                $object['name'] = $object['id'];
                break;
            }

            $object['name'] = null;
        }

        while (!array_key_exists('dateCreated', $object)) {
            // ZGW specifiek
            if (array_key_exists('registratiedatum', $object)) {
                $object['dateCreated'] = $object['registratiedatum'];
                break;
            }

            break;
        }

        /*
    	while(!array_key_exists ('dateModified', $object)){

    		break;
    	}
    	*/
        return $object;
    }

    /*
     * Finds @id keys and replaceses the relative link with an absolute link
     */
    private function convertAtId(array $object, array $parsedUrl)
    {
        if (array_key_exists('@id', $object)) {
            if (
                $this->params->has('app_subpath_routing') &&
                ($this->params->get('app_subpath_routing') && $this->params->get('app_subpath_routing') !== 'false') &&
                (!$this->params->get('app_internal') || $this->params->get('app_internal') === 'false')
            ) {
                $component = $this->getComponentFromUrl($parsedUrl);
                if (strpos($component, 'http') !== false) {
                    $componentUrl = $component;
                } else {
                    $componentUrl = $this->cleanUrl(['component' => $component]);
                }
                $object['@id'] = $componentUrl.$object['@id'];
            } else {
                $object['@id'] = $parsedUrl['scheme'].'://'.$parsedUrl['host'].$object['@id'];
            }
        }
        foreach ($object as $key => $subObject) {
            if (is_array($subObject)) {
                $object[$key] = $this->convertAtId($subObject, $parsedUrl);
            }
        }

        return $object;
    }

    private function getComponentFromUrl(array $parsedUrl): string
    {
        $path = explode('/', $parsedUrl['path']);
        $apiKey = array_search('api', $path);
        $versionKey = array_search('v1', $path);
        if ($apiKey && $versionKey && count($path) > $versionKey + 1) {
            $component = $path[$versionKey + 1];
        } else {
            $component = explode('.', $parsedUrl['host'])[0];
        }
        if (
            $parsedUrl['host'] != $this->params->get('app_domain') &&
            $parsedUrl['host'] != "{$this->params->get('app_env')}.{$this->params->get('app_domain')}" &&
            $parsedUrl['host'] != "$component.{$this->params->get('app_env')}.{$this->params->get('app_domain')}"
        ) {
            if (strpos($component, $parsedUrl['host']) !== false) {
                return $parsedUrl['host'];
            } else {
                return "{$parsedUrl['scheme']}://{$parsedUrl['host']}/api/v1/$component";
            }
        }

        return $component;
    }

    /**
     * Checks if the query is an array and converts it to a query string if that is the case.
     *
     * @param $query Mixed The query as passed on to the commonGroundService
     *
     * @return string The query converted to a string
     */
    private function convertQuery($query): string
    {
        if (is_array($query) && $query != []) {
            $queryString = '';
            $iterator = 0;
            foreach ($query as $parameter => $value) {
                // Lets catch array in the querry (http technically they are aloowd 1 deep)
                $arryIterator = 0;
                if (is_array($value)) {
                    foreach ($value as  $arryValue) {
                        $queryString .= $parameter.'[]='.$arryValue;
                        $arryIterator++;
                        if ($arryIterator < count($value)) {
                            $queryString .= '&';
                        }
                    }
                } else {
                    $queryString .= "$parameter=$value";
                }

                $iterator++;
                if ($iterator < count($query)) {
                    $queryString .= '&';
                }
            }
            $query = $queryString;
        } elseif ($query == []) {
            $query = '';
        }

        // Lets pass trough a locale if required
        if ($this->local) {
            if ($query != '') {
                $query .= '&';
            }
            $query .= '_local='.$this->local;
        }

        return $query;
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function cleanUrl($url = false, $resource = false, $autowire = true)
    {
        // The Url might be an array of component information

        if (is_array($url) && array_key_exists('component', $url)) {
            $route = '';
            if (array_key_exists('type', $url)) {
                $route = $route.'/'.$url['type'];
            }
            if (array_key_exists('id', $url)) {
                $route = $route.'/'.$url['id'];
            }

            // If the component is defined we get the config values
            if ($component = $this->getComponent($url['component'])) {
                $url = $component['location'].$route;

                // Components may overule the autowire
                if (array_key_exists('autowire', $component)) {
                    $autowire = $component['autowire'];
                }
            }
        }
        if (!$url && $resource && array_key_exists('@id', $resource)) {
            $url = $resource['@id'];
        }

        if(is_array($url)){
            $urlString = 'commonground.local';
            key_exists('component', $url) ? $urlString = "{$url['component']}.$urlString" : null;
            key_exists('type', $url) ? $urlString = "$urlString/{$url['type']}":null;
            key_exists('id', $url) ? $urlString = "$urlString/{$url['id']}":null;

            $url = $urlString;
        }
        // Remove trailing slash
        return rtrim($url, '/');
    }

    /*
     * Header overrides for ZGW and Camunda
     */
    public function setCredentials($username, $password)
    {
        $this->headers['auth'] = [$username, $password];
    }

    public function setHeader($key, $value)
    {
        $this->headers[$key] = $value;
    }

    public function getHeader($key)
    {
        return $this->headers[$key];
    }

    /**
     * Returns the content of $this->local.
     *
     * @return string
     */
    public function getLocal()
    {
        return $this->local;
    }

    /**
     * Overwrites the content of $this->local.
     *
     * @param $input
     */
    public function setLocal($input)
    {
        $this->local = $input;
    }

    /*
     * Get a single resource from a common ground componant
     */
    public function getDomain()
    {
        $request = $this->requestStack->getCurrentRequest();
        $host = $request->getHost();

        if ($host == '' | $host == 'localhost') {
            $host = $this->params->get('app_domain');
        }

        $host_names = explode('.', $host);
        $host = $host_names[count($host_names) - 2].'.'.$host_names[count($host_names) - 1];

        return $host;
    }

    /*
     * Get Component settings from the configuration
     *
     * @param array $code The code of the component
     * @param array The components settings
     */
    public function getComponent(?string $code)
    {
        // Create the list
        $components = $this->params->get('common_ground.components');

        // Get the component
        if (array_key_exists($code, $components)) {
            $component = $components[$code];
            $component['code'] = $code;

            return $component;
        }

        // Lets default to a negative
        return false;
    }

    /*
     * Get the health of a commonground componant
     */
    public function getComponentHealth(string $component, $force = false)
    {
        $url = $this->cleanUrl(['component' => $component]);

        $item = $this->cache->getItem('componentHealth_'.md5($component));
        if ($item->isHit() && !$force) {
            return $item->get();
        }

        // Lets actually do a health check
        $headers = $this->headers;

        $component = $this->getComponent($component);
        if ($component && key_exists('accept', $component)) {
            $headers['Accept'] = $component['accept'];
        } else {
            $headers['Accept'] = 'application/health+json';
        }
        // Component specific congiguration

        try {
            $response = $this->client->request('GET', $url, ['headers' => $headers, 'http_errors' => false]);
            if ($response->getStatusCode() == 200) {
                $item->set(true);
            } else {
                $item->set(false);
            }
        } catch (\Exception $e) {
            $item->set(false);
        }

        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        return $item->get();
    }

    /*
     * Get a list of available resources on a commonground componant
     */
    public function getComponentResources(string $component, $force = false)
    {
    }

    /*
     * Create a JWT token from Component settings
     *
     * @param array $component The code of the component
     * @param array The JWT token
     */
    public function getJwtToken(?string $component)
    {
        $component = $this->getComponent($component);

        $userId = '';
        $userRepresentation = '';

        // Create token header as a JSON string
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256', 'client_identifier' => $component['id']]);

        // Create token payload as a JSON string
        $payload = json_encode(['iss' => $component['id'], 'client_id' => $component['id'], 'user_id' => $userId, 'user_representation' => $userRepresentation, 'iat' => time()]);

        // Encode Header to Base64Url String
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));

        // Encode Payload to Base64Url String
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        // Create Signature Hash
        $signature = hash_hmac('sha256', $base64UrlHeader.'.'.$base64UrlPayload, $component['secret'], true);

        // Encode Signature to Base64Url String
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Return JWT
        return $base64UrlHeader.'.'.$base64UrlPayload.'.'.$base64UrlSignature;
    }

    /*
     * Create a UUID from a given url
     *
     * @param array $url The url beind parsed
     * @param array The UUID
     */
    public function getUuidFromUrl(?string $url)
    {
        $array = explode('/', $url);
        /* @todo we might want to validate against uuid and id here */
        return end($array);
    }

    public function dateInterval($string, $format)
    {
        $string = new DateInterval($string);
        $string = $string->format($format);

        return $string;
    }

    public function addDateInterval($date, $interval)
    {
        $date = new \DateTime($date);
        $interval = new DateInterval($interval);

        $date->add($interval)->format('Y-m-d');

        return $date;
    }
}
