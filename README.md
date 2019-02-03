# PHP Micro MVC

Very simple PHP Framework.<br />
(current status: development version)

## Requirements

* PHP 7.1 or later

## Installation
```
git clone https://github.com/yujp/php-micro-mvc.git
```

## Example of Usage

Directory
```
app
├── system
│   ├── actions
│   ├── configs
│   ├── models
│   ├── vendor
│   │   └── php-micro-mvc
│   ├── views
│   ├── bootstrap.php
│   └── composer.json
├── assets
│   └── css, js, images...
└── index.php
```

bootstrap.php
```php
<?php
declare(strict_types=1);
namespace App;

require __DIR__ . '/vendor/autoload.php';

$env = getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV'): 'development';
\MicroMvc\App::run($env, __DIR__);
```

composer.json
```json
{
    "autoload" : {
        "classmap": [
            "vendor/php-micro-mvc/src"
        ]
    }
}
```

## License
This is open source software licensed under the [MIT license](https://opensource.org/licenses/MIT).