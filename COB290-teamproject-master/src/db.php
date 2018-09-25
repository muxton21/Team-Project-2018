<?php // Author: Christopher Stevens

use Silex\Provider\DoctrineServiceProvider;

$app->register(new DoctrineServiceProvider(), array(
    "db.options" => array(
        "dbname" => "cob290",
        "host" => "cob290.cstevens.me",
        "user" => "team01",
        "password" => "@team01!"
    )
));