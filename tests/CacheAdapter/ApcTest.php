<?php

use PHPUnit\Framework\TestCase;
use Proxy\Proxy;
use Proxy\CacheAdapter\Apc;

class ApcTest extends TestCase
{
    private Proxy $proxy;
    private Apc $apc;
    private \MockSubject $subject;

    public function setup() : void
    {
        if (!extension_loaded('apc') && !extension_loaded('apcu')) {
            $this->markTestSkipped("ext/APC or ext/APCu should be loaded");
        }
        if (!ini_get('apc.enabled') || !ini_get('apc.enable_cli')) {
            $this->markTestSkipped("ext/APC(u) is loaded but not enabled, check for
            apc.enabled and apc.enable_cli in php.ini");
        }
        $this->apc     = new Apc();
        $this->proxy   = new Proxy();
        $this->subject = new \MockSubject();
        $this->proxy->setSubjectObject($this->subject);
        $this->proxy->setCacheObject($this->apc);
    }

    public function testApi()
    {
        $this->apc->set('foo', 'bar');
        $this->assertTrue($this->apc->has('foo'));
        $this->assertEquals('bar', $this->apc->get('foo'));
    }

    public function testCacheTime()
    {
        $this->apc->setCacheTime(1200);
        $this->assertEquals(1200, $this->apc->getCacheTime());
    }

    public function testCacheWithRealProxy()
    {
        $this->proxy->mockCall(42);
        $hash = $this->proxy->makeHash([get_class($this->subject), 'mockCall', [42]]);
        $this->assertMatchesRegularExpression("/42/", $this->apc->get($hash));
    }
}