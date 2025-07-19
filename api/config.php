<?php

define( 'DEBUG_MODE', false );
define( 'CACHE_DIR', '/tmp/cache' );          //缓存目录
define( 'HASH_KEY', 'idev' );           //加密密钥, 请修改并勿泄露
define( 'DEFAULT_ICO', __DIR__ . '/../favicon.png');  //默认图标路径
define( 'EXPIRE', 2592000 );             //缓存有效期30天, 单位为:秒，为0时不缓存
