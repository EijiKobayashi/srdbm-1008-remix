<?php

declare(strict_types=1);

return [
    // 画面とCLIの初期値です。末尾の / はあってもなくても構いません。
    'source_url' => 'http://old.example.com',
    'destination_url' => 'https://www.example.com',

    // destination_url が http:// の場合も https:// に補正します。
    'force_https' => true,
    'source_prefix' => '',
    'destination_prefix' => '',
];
