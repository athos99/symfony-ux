<?php

declare(strict_types=1);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = urldecode(parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH) ?: '/');
$file = __DIR__.'/../../public'.$uri;

if ('/' !== $uri && is_file($file)) {
    return false;
}

require __DIR__.'/../../public/index.php';
