# Driver Postal

## Configuration

1. Open config/mail.php and add in `mailers` config:

```php

  'postal' => [
            'transport' => 'postal',
        ],

```

2. Open config/services and add:

```php
   'postal' => [
        'base_uri'   => env('POSTAL_BASE_URI', 'http://localhost:5001'),
        'server_key' => env('POSTAL_API_KEY'),
        'timeout'    => 10,
    ],
```

## Note

-   If you want to work on fixed BASE_URI and fixed API_KEY, just simply define `POSTAL_BASE_URI` and `POSTAL_API_KEY` in `.env`.
-   If you want to work on dynamic BASE_URI and API_KEY, only need to defind in mail configuration settings.
