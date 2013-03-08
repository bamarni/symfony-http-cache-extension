<?php

namespace Bamarni\HttpCache\Store;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows to vary response based on application level context (user logged-in, etc.).
 *
 * @author Bilal Amarni <bilal.amarni@gmail.com>
 */
class ApplicationContextStore extends DoctrineStore implements ContainerAwareInterface
{
    protected $container;
    
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function isLoggedIn()
    {
        $securityContext = $this->container->get('security.context');
        
        if (null === $securityContext->getToken()) {
            return 0;
        }
        
        return intval($securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED'));
    }

    public function getVaryHeaders()
    {
        return array(
            'X-Symfony-Logged-In' => 'isLoggedIn',
        );
    }

    public function loadContext($request)
    {
        if (!isset($_SESSION['http_cache.context'])) {
            return;
        }

        foreach ($_SESSION['http_cache.context'] as $header => $value) {
            $request->headers->set($header, $value);
        }
    }

    public function onKernelResponse($response = null)
    {
        $context = array();
        foreach ($this->getVaryHeaders() as $header => $method) {
            if (null !== $value = $this->{$method}()) {
                $context[$header] = $value;
//                $response->headers->set($header, $context[$header]);
            }
        }

        if (!empty($context)) {
            $_SESSION['http_cache.context'] = $context;
        }
    }
}
