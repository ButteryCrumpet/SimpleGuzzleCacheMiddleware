<?php

namespace SimpleGuzzleCacheMiddleware;

use Psr\SimpleCache\CacheInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Promise\FulfilledPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Caching Middleware for Guzzle and PSR16 compliant caching
 */
class SimpleGuzzleCacheMiddleware
{
    const CACHE_HEADER = "X-PPBuild-Simple-Cache";
    const HIT = "CACHE_HIT";
    const MISS = "CACHE_MISS";
    const SET = "CACHE_SET";
    const INVALID_METHOD = "INVALID_METHOD";
    
    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var array
     */
    private $validMethods = ['GET' => true];

    /**
     * @param CacheInterface $cache
     * @param array $methods array of legal methods (Default GET)
     */
    public function __construct(CacheInterface $cache, $methods = [])
    {
        $this->cache = $cache;
        if (!empty($methods)) {
            $this->validMethods = $methods;
        }
    }

    /**
     * @param callable $handler
     *
     * @return callable
     */
    public function __invoke(callable $handler)
    {
        return function (RequestInterface $request, array $options) use (&$handler) {
            if (!isset($this->validMethods[strtoupper($request->getMethod())])) {
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) {
                        return $response->withHeader(self::CACHE_HEADER, self::INVALID_METHOD);
                    }
                );
            }

            $uri = (string)$request->getUri();
            $cachedResponse = $this->getCachedResponse($uri);
            if ($cachedResponse !== self::MISS) {
                $response = $cachedResponse->withHeader(self::CACHE_HEADER, self::HIT);
                return new FulfilledPromise($response);
            }

            $promise = $handler($request, $options);
            return $promise->then(
                function (ResponseInterface $response) use ($uri) {
                    if ($response->getStatusCode() <= 400) {
                        $this->setCachedResponse($uri, $response);
                        if ($response->getBody()->isSeekable()) {
                            $response->getBody()->seek(0);
                        }
                    }
                    return $response->withHeader(self::CACHE_HEADER, self::SET);
                },
                function (ResponseInterface $response) {
                    return $response;
                }
            );
        };
    }

    /**
     * Gets a cached request and parses it to a RequstObject or returns CACHE_MISS
     * 
     * @param string $uri
     * 
     * @return GuzzleHttp\Psr7\Response|string
     */
    private function getCachedResponse($uri)
    {
        $cached = $this->cache->get($uri, self::MISS);

        if ($cached === self::MISS) {
            return self::MISS;
        }

        try {
            $response = Psr7\parse_response($cached);
        } catch (\InvalidArgumentException $e) {
            return self::MISS;
        }
        return $response;
    }

    /**
     * Stringifies response and sets in cache
     * 
     * @param string $uri
     * @param GuzzleHttp\Psr7\Response $response
     * 
     * @return bool $success
     */
    private function setCachedResponse($uri, $response)
    {
        return $this->cache->set($uri, Psr7\str($response));
    }
}
