<?php // Author: Christopher Stevens

use Silex\Application;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\MonologServiceProvider;

$app = new Application();
$app->register(new SessionServiceProvider());
$app->register(new MonologServiceProvider(), array(
    "monolog.logfile" => __DIR__."/../mono.log"
));

require "db.php";
require "security.php";
require "twig.php";

$app["debug"] = true;

return $app;
