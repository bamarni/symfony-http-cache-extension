# EXPERIMENTAL (WIP)

Some stores + custom http_cache

# Http Cache Doctrine Store

Store implementation for Symfony HttpCache relying on Doctrine Cache.

## Instructions

### Install the package

Add the following dependency to your composer.json file:
``` json
{
    "require": {
        "_some_packages": "...",

        "bamarni/symfony-http-cache-extension": "*"
    }
}
```

### Edit your project's HttpCache

Configure a Doctrine Cache driver and pass it to a DoctrineStore :

``` php
<?php
// app/AppCache.php

use Bamarni\HttpCache\HttpCache;
use Bamarni\HttpCache\Store\DoctrineStore;
//use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\MemcacheCache;

class AppCache extends HttpCache
{
    public function createStore()
    {
        // Memcache
        $memcache = new \Memcache;
        $memcache->connect('localhost', 11211);
        $driver = new MemcacheCache;
        $driver->setMemcache($memcache);

        // or APC
        //$driver = new ApcCache;

        return new DoctrineStore($driver);
    }
}

```
