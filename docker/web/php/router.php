<?php

// Router of `php -S` in the production web image (docker/web/Dockerfile.prod, D15), used instead of
// Laravel's vendor/.../Foundation/resources/server.php. That script returns false for every existing
// file under public/, so the built-in server answered /build/*, /robots.txt, /favicon.ico and
// /.htaccess itself: none of the SecurityHeaders headers (nosniff, X-Frame-Options, HSTS) and no
// TrustProductionHosts Host check. Here every request reaches Laravel; the allowlisted public files
// are served by App\Http\Controllers\PublicAssetController with the full global middleware stack.
// Methods the built-in server does not know (QUERY, FOO...) never reach this script: the host nginx
// rejects them (docs/deploy.md) and scripts/deploy/server-deploy.sh fails when FOO through APP_URL still leaks X-Powered-By.

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Same access log line as Laravel's server.php (docker compose logs web), without control characters
// from the decoded URI (no forged log lines).
$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];
file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: ".preg_replace('/[\x00-\x1F\x7F]/', '?', $uri)."\n");

// For an existing file the built-in server sets SCRIPT_NAME to that file (/robots.txt), and Symfony then
// takes it as the base URL: the path info becomes "/" and the request would hit the home route. Present
// every request as a request to the front controller, like Apache or nginx with try_files.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';

require_once $publicPath.'/index.php';
