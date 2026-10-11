<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

use App\Http\Response;
use App\Http\Session;

Session::start($config);
Session::logout();

Response::redirect(Session::baseUrl($config) . '/login.php');