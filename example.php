<?php

include 'TenMinuteMail.php';

$service = new \TenMinuteMail\Service(uniqid(), './');
$service->getNewAddress();
echo "\n-> Your Address is:\t".$service->getEmail()."\n";
while(true)
{
    $service->check();
    echo "\n*---------------------------------------------------------*\n";
    echo "Your Addres will expire in {$service->getRemainingTime()} minutes.\n";
    if($service->getRemainingTime() <= 2) {
        $service->renew();
    }
    $o = $service->getMails();
    echo 'You have '.count($o)." e-mails.\n";
    if(count($o) > 0) {
        var_dump($o);
    }
    sleep(20);
}

// close the connection
unset($service);