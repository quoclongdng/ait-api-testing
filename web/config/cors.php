<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The /api/v1 JSON API is internal (D11, D12): only the dashboard calls it, from the same origin, with
| the session cookie. No path is shared cross-origin, so no response carries Access-Control-Allow-*
| headers and a foreign site cannot read even the unauthenticated ones such as /api/v1/health
| (S13-03). Without this file the framework default answers every api/* route with
| `Access-Control-Allow-Origin: *`.
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
