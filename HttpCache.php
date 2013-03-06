<?php

namespace Bamarni\HttpCache;

use Parizz\MainBundle\HttpCache\Esi;
use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;
use Bamarni\HttpCache\Store\ApplicationContextStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class HttpCache extends HttpCache
{
    protected $store;

    public function __construct(HttpKernelInterface $kernel, $cacheDir = null)
    {
        $this->store = $this->createStore();

        parent::__construct($kernel, $this->store, $this->createEsi(), array_merge(array('debug' => $kernel->isDebug()), $this->getOptions()));
    }

    protected function createEsi()
    {
        return new Esi();
    }

    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        if ($this->store instanceof ApplicationContextStore) {
            $this->store->loadContext($request);
        }

        return parent::handle($request, $type, $catch);
    }

    protected function forward(Request $request, $raw = false, Response $entry = null)
    {
        if ($this->store instanceof ApplicationContextStore) {
            $this->kernel->boot();

            $container = $this->kernel->getContainer();
            $this->store->setContainer($container);

            $container->set('http_cache.store', $this->store);
        }

        return parent::forward($request, $raw, $entry);
    }
}
