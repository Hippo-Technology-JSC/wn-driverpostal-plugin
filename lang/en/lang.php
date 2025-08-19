<?php

return [
    'plugin' => [
        'name' => 'Postal Driver',
        'description' => 'Driver that adds support for the Postal SDK (Postal mail driver) to WinterCMS',
    ],

    'postal_base_url' => 'Postal Base URL',
    'postal_base_url_placeholder' => 'Enter your Postal base URL',
    'postal_base_url_comment' => 'Example: <b>http://localhost:5001</b> or <b>https://postal.your-domain.com</b>',
    'postal_key' => 'Postal key',
    'postal_key_placeholder' => 'Enter your Postal API key',
    'postal_key_comment' => 'On Postal UI → Servers → Credentials → type API to create & copy API key.',

    'stream_uploads' => [
        'upload_failed' => 'The file failed to upload',
        'max_size_exceeded' => 'The filesize exceeds the maximum allowed upload size (:size)',
    ],
];
