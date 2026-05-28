<?php

$url = 'https://httpbin.org/get';
echo "Fetching $url via implicit async curl...\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
echo "Response from " . $data['url'] . " received!\n";
echo "My IP is: " . $data['origin'] . "\n";

echo "\nUsing pre-injected \$dory scope:\n";
echo "Scope ID: " . $GLOBALS['dory']->id . "\n";
