<?php // Author: Christopher Stevens

use Silex\Provider\TwigServiceProvider;

$app->register(new TwigServiceProvider());
$app["twig"] = $app->extend("twig", function($twig, $app) {
    $twig->addExtension(new Twig_Extensions_Extension_I18n());
    return $twig;
});
$app["twig.path"] = array(__DIR__."/../templates");
