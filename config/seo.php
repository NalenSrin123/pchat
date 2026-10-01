<?php

$siteUrl = rtrim((string) env('PCHAT_SEO_SITE_URL', env('APP_URL')), '/');
$host = parse_url($siteUrl, PHP_URL_HOST);

return [
    // Do not emit crawlable canonical/social URLs for local development hosts.
    'site_url' => filter_var($siteUrl, FILTER_VALIDATE_URL)
        && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        ? $siteUrl
        : null,
    'social_image' => '/images/pchat-social.svg',
];
