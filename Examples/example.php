<?php

use Rx\SpotRequest;

require_once(__DIR__.'/../vendor/autoload.php');

$spotRequest = new SpotRequest();
//$spotRequest->reserveSpot();
//$activeSpot = $spotRequest->getActiveSpot();
//print_r($activeSpot);
//$spots = $spotRequest->getSpotRequests();
//print_r($spots);

for($i = 0;  $i < 20 ; $i++ ){
    $spotRequest->refreshIp();
    echo $spotRequest->getIp()."\n";
    sleep(2);
}