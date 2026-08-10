<?php

$publicPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

require $publicPath.DIRECTORY_SEPARATOR.'index.php';
