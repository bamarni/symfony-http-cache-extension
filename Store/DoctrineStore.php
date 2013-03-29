<?php

namespace Bamarni\HttpCache\Store;

use Doctrine\Common\Cache\Cache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

/**
 * Store relying on Doctrine Cache.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Bilal Amarni <bilal.amarni@gmail.com>
 */
class DoctrineStore implements StoreInterface
{
    private $driver;
    private $keyCache;
    private $locks;

    /**
     * Constructor.
     *
     * @param string $driver A configured doctrine cache driver.
     */
    public function __construct(Cache $driver)
    {
        $this->driver = $driver;
        $this->keyCache = new \SplObjectStorage();
        $this->locks = array();
    }

    /**
     * Cleanups storage.
     */
    public function cleanup()
    {
        // unlock everything
        foreach ($this->locks as $lock) {
            $this->driver->delete($lock);
        }

        $error = error_get_last();
        if (1 === $error['type'] && false === headers_sent()) {
            // send a 503
            header('HTTP/1.0 503 Service Unavailable');
            header('Retry-After: 10');
            echo '503 Service Unavailable';
        }
    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request)
    {
        $key = $this->getCacheKey($request).'.lck';

        if (false !== $this->driver->save($key, '1')) {
            $this->locks[] = $key;

            return true;
        }

        return !$this->driver->contains($key) ?: $key;
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request)
    {
        $key = $this->getCacheKey($request).'.lck';

        return $this->driver->contains($key) ? $this->driver->delete($key) : false;
    }

    public function isLocked(Request $request)
    {
        return $this->driver->contains($this->getCacheKey($request).'.lck');
    }

    /**
     * Locates a cached Response for the Request provided.
     *
     * @param Request $request A Request instance
     *
     * @return Response|null A Response instance, or null if no cache entry was found
     */
    public function lookup(Request $request)
    {
        $key = $this->getCacheKey($request);

        if (!$entries = $this->getMetadata($key)) {
            return null;
        }

        // find a cached entry that matches the request.
        $match = null;
        foreach ($entries as $entry) {
            if ($this->requestsMatch(isset($entry[1]['vary'][0]) ? $entry[1]['vary'][0] : '', $request->headers->all(), $entry[0])) {
                $match = $entry;

                break;
            }
        }

        if (null === $match) {
            return null;
        }

        list($req, $headers) = $match;
        if ($body = $this->driver->fetch($headers['x-content-digest'][0])) {
            return $this->restoreResponse($headers, $body);
        }

        // TODO the metaStore referenced an entity that doesn't exist in
        // the entityStore. We definitely want to return nil but we should
        // also purge the entry from the meta-store when this is detected.
        return null;
    }

    /**
     * Writes a cache entry to the store for the given Request and Response.
     *
     * Existing entries are read and any that match the response are removed. This
     * method calls write with the new list of cache entries.
     *
     * @param Request  $request  A Request instance
     * @param Response $response A Response instance
     *
     * @return string The key under which the response is stored
     *
     * @throws \RuntimeException
     */
    public function write(Request $request, Response $response)
    {
        $key = $this->getCacheKey($request);
        $storedEnv = $this->persistRequest($request);

        // write the response body to the entity store if this is the original response
        if (!$response->headers->has('X-Content-Digest')) {
            $digest = $this->generateContentDigest($response);

            if (false === $this->save($digest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $digest);

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
        }

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = array();
        $vary = $response->headers->get('vary');
        foreach ($this->getMetadata($key) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = array('');
            }

            if ($vary != $entry[1]['vary'][0] || !$this->requestsMatch($vary, $entry[0], $storedEnv)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->persistResponse($response);
        unset($headers['age']);

        array_unshift($entries, array($storedEnv, $headers));

        if (false === $this->save($key, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        return $key;
    }

    /**
     * Returns content digest for $response.
     *
     * @param Response $response
     *
     * @return string
     */
    protected function generateContentDigest(Response $response)
    {
        return 'en'.sha1($response->getContent());
    }

    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     *
     * @throws \RuntimeException
     */
    public function invalidate(Request $request)
    {
        $modified = false;
        $key = $this->getCacheKey($request);

        $entries = array();
        foreach ($this->getMetadata($key) as $entry) {
            $response = $this->restoreResponse($entry[1]);
            if ($response->isFresh()) {
                $response->expire();
                $modified = true;
                $entries[] = array($entry[0], $this->persistResponse($response));
            } else {
                $entries[] = $entry;
            }
        }

        if ($modified) {
            if (false === $this->save($key, serialize($entries))) {
                throw new \RuntimeException('Unable to store the metadata.');
            }
        }

        // As per the RFC, invalidate Location and Content-Location URLs if present
        foreach (array('Location', 'Content-Location') as $header) {
            if ($uri = $request->headers->get($header)) {
                $subRequest = Request::create($uri, 'get', array(), array(), array(), $request->server->all());

                $this->invalidate($subRequest);
            }
        }
    }

    /**
     * Determines whether two Request HTTP header sets are non-varying based on
     * the vary response header value provided.
     *
     * @param string $vary A Response vary header
     * @param array  $env1 A Request HTTP header array
     * @param array  $env2 A Request HTTP header array
     *
     * @return Boolean true if the the two environments match, false otherwise
     */
    private function requestsMatch($vary, $env1, $env2)
    {
        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = strtr(strtolower($header), '_', '-');
            $v1 = isset($env1[$key]) ? $env1[$key] : null;
            $v2 = isset($env2[$key]) ? $env2[$key] : null;
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets all data associated with the given key.
     *
     * Use this method only if you know what you are doing.
     *
     * @param string $key The store key
     *
     * @return array An array of data associated with the key
     */
    private function getMetadata($key)
    {
        if (false === $entries = $this->load($key)) {
            return array();
        }

        return unserialize($entries);
    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return Boolean true if the URL exists and has been purged, false otherwise
     */
    public function purge($url)
    {
        if ($this->driver->contains($key = $this->getCacheKey(Request::create($url)))) {
            $this->driver->delete($key);
 
            return true;
        }
 
        return false;
    }

    /**
     * Loads data for the given key.
     *
     * @param string $key The store key
     *
     * @return string The data associated with the key
     */
    public function load($key)
    {
        return $this->driver->contains($key) ? $this->driver->fetch($key) : false;
    }

    /**
     * Save data for the given key.
     *
     * @param string $key  The store key
     * @param string $data The data to store
     *
     * @return Boolean
     */
    public function save($key, $data)
    {
        $this->driver->save($key, $data);

        if ($data != $this->driver->fetch($key)) {
            return false;
        }
    }

    /**
     * Returns a cache key for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return string A key for the given Request
     */
    private function getCacheKey(Request $request)
    {
        if (isset($this->keyCache[$request])) {
            return $this->keyCache[$request];
        }

        return $this->keyCache[$request] = 'md'.sha1($request->getUri());
    }

    /**
     * Persists the Request HTTP headers.
     *
     * @param Request $request A Request instance
     *
     * @return array An array of HTTP headers
     */
    private function persistRequest(Request $request)
    {
        return $request->headers->all();
    }

    /**
     * Persists the Response HTTP headers.
     *
     * @param Response $response A Response instance
     *
     * @return array An array of HTTP headers
     */
    private function persistResponse(Response $response)
    {
        $headers = $response->headers->all();
        $headers['X-Status'] = array($response->getStatusCode());

        return $headers;
    }

    /**
     * Restores a Response from the HTTP headers and body.
     *
     * @param array  $headers An array of HTTP headers for the Response
     * @param string $body    The Response body
     *
     * @return Response
     */
    private function restoreResponse($headers, $body = null)
    {
        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);

        return new Response($body, $status, $headers);
    }
}
