<?php

use SimpleGuzzleCacheMiddleware\SimpleGuzzleCacheMiddleware;
use PHPUnit\Framework\TestCase;
use SuperSimpleCache\SuperSimpleCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7;

class SimpleGuzzleCacheMiddlewareTest extends TestCase
{
    private $cacheDir;
    private $cache;
    private $cacheMidWare;

    public function setUp()
    {
        $this->cacheDir = dirname(__FILE__) . "/cache";
        mkdir($this->cacheDir);
        $this->cache = new SuperSimpleCache($this->cacheDir);
        $this->cacheMidWare = new SimpleGuzzleCacheMiddleware($this->cache);
    }

    public function testCreatesNewCache()
    {
        $mock = new MockHandler([new Response(200, [], "ho")]);
        $stack = HandlerStack::create($mock);
        $stack->push($this->cacheMidWare);
        $client = new Client([
            'handler' => $stack,
        ]);
        $client->request("GET", "www.google.com");
        $this->assertTrue($this->cache->has("www.google.com"));
    }

    public function testReadsFromCacheOnHit()
    {
        $url = "www.something.com";
        $body = "hihoho";
        $cached_response = new Response(200, [], $body);
        $cached_response = $cached_response
            ->withHeader(
                $this->cacheMidWare::CACHE_HEADER,
                $this->cacheMidWare::HIT
            );
        $this->cache->set($url, Psr7\str($cached_response));

        $mock = new MockHandler([$cached_response]);
        $stack = HandlerStack::create($mock);
        $stack->push($this->cacheMidWare);
        $client = new Client([
            'handler' => $stack,
        ]);
        $response = $client->request("GET", $url);

        // serializing and de-serializing alters the signature of the body stream resource
        // $this->assertEquals($cached_response, $response, "responses are equal");

        // assert effectively equal
        $this->assertEquals($response->getBody()->getContents(), $body, "body is correct");
        $this->assertEquals($response->getStatusCode(), $cached_response->getStatusCode(), "status code is correct");
        $this->assertEquals($response->getHeaders(), $cached_response->getHeaders(), "headers are correct");
        $this->assertEquals($response->getProtocolVersion(), $cached_response->getProtocolVersion(), "protocol version is correct");
        $this->assertEquals($response->getReasonPhrase(), $cached_response->getReasonPhrase(), "reason phrase is correct");
    }

    public function testSkipsCacheForInvalidMethods()
    {
        $url = "www.something.com";
        $body = "hihoho";
        $cached_response = new Response(200, [], $body);
        $cached_response = $cached_response
            ->withHeader(
                $this->cacheMidWare::CACHE_HEADER,
                $this->cacheMidWare::INVALID_METHOD
            );
        $this->cache->set($url, Psr7\str($cached_response));

        $mock = new MockHandler([$cached_response]);
        $stack = HandlerStack::create($mock);
        $stack->push($this->cacheMidWare);
        $client = new Client([
            'handler' => $stack,
        ]);
        $response = $client->request("POST", $url);
        $this->assertEquals($response->getHeader($this->cacheMidWare::CACHE_HEADER)[0], $this->cacheMidWare::INVALID_METHOD);
    }

    public function tearDown()
    {
        $this->cache->clear();
        rmdir($this->cacheDir);
    }
}
