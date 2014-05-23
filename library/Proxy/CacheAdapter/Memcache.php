<?php
namespace Proxy\CacheAdapter;

class Memcache implements Cacheable
{
    private \Memcached $cache;

    private int $cacheTime;

    public function __construct(string $server, int $port)
    {
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException("ext/memcached is required");
        }
        $this->cache = new \Memcached();
        if (!$this->cache->addServer($server, $port)) {
            throw new \InvalidArgumentException("Memcache server $server:$port is invalid");
        }
    }

    public function get(string $item) : mixed
    {
        return $this->cache->get($item);
    }

    public function set(string $item, $value)
    {
        $this->cache->set($item, $value, null, $this->cacheTime);
    }

    public function has(string $item) : bool
    {
        return $this->cache->get($item) !== false;
    }

    public function setCacheTime(int $time) : self
    {
        $this->cacheTime = $time;

        return $this;
    }

    public function getCacheTime() : int
    {
        return $this->cacheTime;
    }
}