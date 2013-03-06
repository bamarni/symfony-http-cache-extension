<?php

namespace Bamarni\HttpCache\Esi;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\EsiResponseCacheStrategyInterface;

class EsiResponseCacheStrategy implements EsiResponseCacheStrategyInterface
{
    private $cacheable = true;
    private $embeddedResponses = 0;
    private $ttls = array();
    private $maxAges = array();

    /**
     * Adds a Response.
     *
     * @param Response $response
     */
    public function add(Response $response)
    {
        if ($response->isValidateable()) {
            $this->cacheable = false;
        } else {
            $this->ttls[] = $response->getTtl();
            $this->maxAges[] = $response->getMaxAge();
        }

        $this->embeddedResponses++;
    }

    /**
     * Updates the Response HTTP headers based on the embedded Responses.
     *
     * @param Response $response
     */
    public function update(Response $response)
    {
        // if we have no embedded Response, do nothing
        if (1 === $this->embeddedResponses) {
            return;
        }

        // Remove validation related headers in order to avoid browsers using
        // their own cache, because some of the response content comes from
        // at least one embedded response, which may have a different caching strategy.
        if ($response->isValidateable()) {
            $response->setEtag(null);
            $response->setLastModified(null);
        }

        if (!$this->cacheable) {
            $response->headers->set('Cache-Control', 'no-cache, must-revalidate');

            return;
        }

        $this->ttls[] = $response->getTtl();
        $this->maxAges[] = $response->getMaxAge();

        if (null !== $maxAge = min($this->maxAges)) {
            $response->setSharedMaxAge($maxAge);
            $response->headers->set('Age', $maxAge - min($this->ttls));
        }
        $response->setMaxAge(0);
    }
}
