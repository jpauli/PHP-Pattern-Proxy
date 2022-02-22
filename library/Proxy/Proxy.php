<?php
/**
* PHP-Proxy-Pattern
*
* Copyright (c) 2010, Julien Pauli <jpauli@php.net>.
* All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions
* are met:
*
* * Redistributions of source code must retain the above copyright
* notice, this list of conditions and the following disclaimer.
*
* * Redistributions in binary form must reproduce the above copyright
* notice, this list of conditions and the following disclaimer in
* the documentation and/or other materials provided with the
* distribution.
*
* * Neither the name of Julien Pauli nor the names of his
* contributors may be used to endorse or promote products derived
* from this software without specific prior written permission.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
* "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
* LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
* FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
* COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
* BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
* LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
* CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
* LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
* ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
* @author Julien Pauli <jpauli@php.net>
* @copyright 2010 Julien Pauli <jpauli@php.net>
* @license http://www.opensource.org/licenses/bsd-license.php BSD License
*/

namespace Proxy;

use Proxy\CacheAdapter\Cacheable;

/**
* This is the proxy object implementing a Proxy design pattern.
* It takes a subject and a cache object as params.
* A method call on the proxy will proxy it to the subject and put the
* result in the cache object for next calls to be prevented on the subject.
* TTL is implemented, as well as cache hit count.
*
* Aggregate as 1,n : Only one cache object and one subject object
* at the same time
*
* @author Julien Pauli <jpauli@php.net>
* @copyright 2010 Julien Pauli <jpauli@php.net>
* @license http://www.opensource.org/licenses/bsd-license.php BSD License
* @version Release: @package_version@
*/
class Proxy
{
    /**
     * Default timeout for cache entries
     */
    const DEFAULT_TIMEOUT = 120;

    /**
     * Default hash callback to compute the hash
     * for an item
     */
    const DEFAULT_HASH_FUNCTION = 'md5';

    /**
     * Object to cache method results from
     */
    private ?object $subjectObject = null;

    /**
     * Hash function used to compute a hash
     * from a method call on the subject object
     * Should be a valid PHP callback
     */
    private $hashFunction = self::DEFAULT_HASH_FUNCTION;

    /**
     * CacheAdapter to use
     */
    private ?Cacheable $cacheObject = null;

    /**
     * Number of cache hits for a specific hash
     */
    private array $cacheHits = [];

    /**
     * Proxy methods to compare to Subject methods
     * Proxy methods are cached into this property
     */
    private array $thisMethods = [];

    /**
     * Sets a subject to cache methods from
     *
     * @throws \InvalidArgumentException
     */
    public function setSubjectObject(object $o) : self
    {
        $this->subjectObject = $o;
        $this->checkForConsistency();

        return $this;
    }

    /**
     * Checks that the subject doesn't have the
     * same methods as the proxy. Proxy uses __call(), so...
     *
     * @throws \LogicException
     */
    private function checkForConsistency() : self
    {
        if (!$this->thisMethods) {
            $reflection = new \ReflectionObject($this);
            $this->thisMethods = array_filter(
                       $reflection->getMethods(),
                       function ($val){return $val->isPublic() && $val->getName() !== '__call';});
            $this->thisMethods = array_map(
                       function ($val) {return $val->getName();},
                       $this->thisMethods);
        }
        if ($comonMethods = array_intersect($this->thisMethods, get_class_methods($this->subjectObject))) {
            throw new \LogicException(sprintf("Methods %s are not allowed in the subject", implode(' ', $comonMethods)));
        }

        return $this;
    }

    /**
     * Generic proxy method
     * The hash is based on array(subjectclass, methodcalled, array(params))
     *  to avoid collisions
     *
     * @throws \DomainException
     * @throws \RuntimeException
     * @throws \BadMethodCallException
     */
    public function __call($meth, $args)
    {
        if (!$this->cacheObject || !$this->subjectObject) {
            throw new \DomainException("Cache object or subject object not set");
        }
        $hash = $this->makeHash([$this->subjectObject::class, $meth, $args]);
        if ($this->cacheObject->has($hash)) {
            $this->setCacheHit($hash);
            return $this->cacheObject->get($hash);
        }
        if (method_exists($this->subjectObject, $meth)) {
            error_clear_last();
            try {
                $return = @$this->subjectObject->$meth(...$args);
            } catch (\Throwable $e) {
                throw new \BadFunctionCallException("Proxy method call error", previous:$e);
            }
            $error = error_get_last();
            if ($error) {
                throw new \RuntimeException($error['message']);
            }
            $this->cacheObject->set($hash, $return);
            $this->cacheHits[$hash] = 0;
            return $return;
        }
        throw new \BadMethodCallException("Method $meth doesnt exists");
    }

    /**
     * Increments cache hits for this hash
     *
     * @throws InvalidArgumentException if the hash doesn't exist
     */
    private function setCacheHit(string $hash) : int
    {
        if (array_key_exists($hash, $this->cacheHits)) {
            return ++$this->cacheHits[$hash];
        }
        throw new \InvalidArgumentException("Cache key $hash does not exist");
    }

    /**
     * Setter for Cacheable object
     */
    public function setCacheObject(Cacheable $cache, int $timeout = self::DEFAULT_TIMEOUT) : self
    {
        $cache->setCacheTime($timeout);
        $this->cacheObject = $cache;

        return $this;
    }

    /**
     * Retrieves the cache object used
     */
    public function getCacheObject() : Cacheable
    {
        return $this->cacheObject;
    }

    /**
     * Computes a hash with the hash function registered
     */
    public function makeHash(array $params) : string
    {
        $h = $this->hashFunction;
        return $h(serialize($params));
    }

    /**
     * Sets a hash callback to be used later
     * for computing the hash
     *
     * @throws InvalidArgumentException for invalid callbacks
     */
    public function setHashFunction(callable $hashFunction) : self
    {
        $this->hashFunction = $hashFunction;

        return $this;
    }

    /**
     * Gets the hash function used
     */
    public function getHashFunction() : callable
    {
        return $this->hashFunction;
    }

    /**
     * Gets the number of cache hits for a specific
     * entry. Entry can be set as a hash value or as
     * an 'unhashed' value aka: array($obj, 'method', array($args))
     *
     * @throws \InvalidArgumentException
     */
    public function getCacheHits($hashOrCall, array $params = null) : int
    {
        if (is_array($hashOrCall) && is_callable($hashOrCall) && $params !== null) {
            $hashOrCall[0] = get_class($hashOrCall[0]);
            $hashOrCall    = array_merge($hashOrCall, [$params]);
            return $this->cacheHits[$this->makeHash($hashOrCall)];
        } elseif (is_string($hashOrCall)) {
            if (array_key_exists($hashOrCall, $this->cacheHits)) {
                return $this->cacheHits[$hashOrCall];
            }
            throw new \InvalidArgumentException("Unknown hash");
        }
        throw new \InvalidArgumentException("Callback or string hash expected");
    }
}