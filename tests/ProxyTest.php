<?php

use PHPUnit\Framework\TestCase;
use Proxy\CacheAdapter as Adapter;
use Proxy\Proxy;

class ProxyTest extends TestCase
{
    private Adapter\Mock $mockCache;
    private \MockSubject $mockSubject;
    private Proxy $proxy;

    public function setUp() : void
    {
        $this->proxy       = new Proxy();
        $this->mockCache   = new Adapter\Mock;
        $this->mockSubject = new \MockSubject();
        $this->proxy->setCacheObject($this->mockCache);
        $this->proxy->setSubjectObject($this->mockSubject);
    }

    public function assertPreconditions() : void
    {
        $this->assertSame($this->mockCache, $this->proxy->getCacheObject());
        $this->assertEquals(Proxy::DEFAULT_HASH_FUNCTION, $this->proxy->getHashFunction());
        $this->assertEquals(Proxy::DEFAULT_TIMEOUT, $this->proxy->getCacheObject()->getCacheTime());
    }

    public function testHashFunction()
    {
        $this->proxy->setHashFunction(sha1(...));
        $this->assertIsCallable($this->proxy->getHashFunction());
    }

    public function testTimeoutIsGivenToCacheBackendByProxy()
    {
        $this->proxy->setCacheObject($this->mockCache, 20);
        $this->assertEquals(20, $this->proxy->getCacheObject()->getCacheTime());
    }

    public function testProxyWithAMissingSubjectObjectThrowsException()
    {
        $this->expectException(\DomainException::class);
        $p = new Proxy;
        $p->foo();
    }

    public function testProxyWithAMissingCachingObjectThrowsException()
    {
        $this->expectException(\DomainException::class);
        $p = new Proxy;
        $p->setSubjectObject($this->mockSubject);
        $p->foo();
    }

    public function testBadMethodCallOnProxyThrowsException()
    {
        $this->expectException(\BadFunctionCallException::class);
        $this->proxy->mockCall(/*with no args*/);
    }

    public function testCallingANonExistantMethodOnProxyThrowsException()
    {
        $this->expectException(\BadMethodCallException::class);
        $this->proxy->foobarbaz();
    }

    public function testProxyProxiesAndCaches()
    {
        $arg = "foobar";
        $this->proxy->mockCall($arg);
        $hash = $this->proxy->makeHash([get_class($this->mockSubject), 'mockCall', [$arg]]);
        $this->assertIsString($this->mockCache->get($hash));
        $this->assertStringMatchesFormat(\MockSubject::MESSAGE, $this->mockCache->get($hash));
    }

    public function testProxyIncrementsCacheHits()
    {
        $arg = "foobar";
        $this->proxy->mockCall($arg);

        $this->assertEquals(0, $this->proxy->getCacheHits([$this->mockSubject, 'mockCall'], [$arg]));

        $this->proxy->mockCall($arg);
        $this->assertEquals(1, $this->proxy->getCacheHits([$this->mockSubject, 'mockCall'], [$arg]));

        $this->proxy->mockCall($arg."modified");
        $this->assertEquals(1, $this->proxy->getCacheHits([$this->mockSubject, 'mockCall'], [$arg]));
    }

    public function testProxyLoadsDataFromCache()
    {
        $this->proxy->setSubjectObject($puMockSubject = $this->createMock("MockSubject"));
        $puMockSubject->expects($this->once()/*once and only once*/)
                      ->method("mockCall")
                      ->will($this->returnValue("return"));

        $this->proxy->mockCall(0);

        $hash = $this->proxy->makeHash([get_class($puMockSubject), 'mockCall', [0]]);

        $this->assertTrue($this->mockCache->has($hash));
        $this->proxy->mockCall(0);
        $this->assertEquals(1, $this->proxy->getCacheHits($hash));
    }

    public function testCollision()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("not allowed");
        $this->proxy->setSubjectObject(new \Foo);
    }
}