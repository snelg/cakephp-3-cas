# CAS Authentication for CakePHP 3.x

Very basic CAS Authentication for CakePHP 3.

## Installing via composer

Install into your project using [composer](http://getcomposer.org).
For existing applications you can add the
following to your composer.json file:

    "require": {
        "snelg/cakephp-3-cas": "dev-master"
    }

And run `php composer.phar update`

## Usage

Add to your list of AuthComponent authenticators, usually in the beforeFilter
function of AppController:

```php
$this->Auth->config('authenticate', [
    'CasAuth.Cas' => [
        'hostname' => 'cas.mydomain.com',
        'port' => 443,
        'uri' => 'authpath']]);
```

CAS parameters "hostname", "port", and "uri" can be specified as above, or by
writing to the "CAS" key in Configure::write, e.g.

```php
Configure::write('CAS.hostname', 'cas.myhost.com');
Configure::write('CAS.uri', 'authpath');
```

Additional optional parameters:
* "debug" : if true, then phpCAS will write debug info to logs/phpCAS.log
* "cert_path": if set, then phpCAS will use the specified CA certificate file to verify the CAS server