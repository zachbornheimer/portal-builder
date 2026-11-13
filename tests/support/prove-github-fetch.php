<?php

$url = 'https://api.github.com/repos/zachbornheimer/portal-builder/releases/latest';
$r = wp_remote_get($url, array(
    'timeout' => 15,
    'headers' => array(
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'DragonGate-Portals',
    ),
));
if (is_wp_error($r)) {
    echo 'err=' . $r->get_error_message() . "\n";
    return;
}
echo 'code=' . wp_remote_retrieve_response_code($r) . "\n";
echo 'body=' . substr((string) wp_remote_retrieve_body($r), 0, 240) . "\n";
