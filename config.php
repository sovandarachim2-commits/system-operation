<?php

// Basic configuration for Order System

// Database settings
$DB_HOST = 'localhost';
$DB_NAME = 'report system';
$DB_USER = 'root';
$DB_PASS = '';

// Timezone
date_default_timezone_set('Asia/Bangkok');

// Telegram settings (fill with your bot credentials)
$TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
// Legacy single chat id (still used if TELEGRAM_TARGETS is empty)
$TELEGRAM_CHAT_ID   = '-5055882974';

// Optional: dedicated Telegram bot token for reminder management.
// If empty, reminder module falls back to TELEGRAM_BOT_TOKEN.
$REMINDER_TELEGRAM_BOT_TOKEN = '8678367286:AAGTeMCXfmAUkEnD0zauhb2aOG3jDjCAeI4';


// Optional: dedicated Telegram bot token for scanner module.
// If empty, scanner falls back to TELEGRAM_BOT_TOKEN above.
$SCANNER_TELEGRAM_BOT_TOKEN = '8656158761:AAF03YbK3YOhgkYdKxU1uHf26liUUesnfek';

// Scanner image storage: 'local' or 'r2'
$SCANNER_STORAGE_DRIVER = 'r2';

// Optional: Cloudflare R2 settings for scanner image uploads.
// When SCANNER_STORAGE_DRIVER is 'r2', fill these values.
$SCANNER_R2_ACCOUNT_ID = '883e51be5fb2549ab2fe3d20d81f4895';
$SCANNER_R2_BUCKET = 'muru-data';
$SCANNER_R2_ACCESS_KEY_ID = '26fe902148726fa89a29a686f9574ce4';
$SCANNER_R2_SECRET_ACCESS_KEY = '8bf8e892125c13c8704cfc77a540e2e7f3206c29aded96a6a73333c67d20ea87';
$SCANNER_R2_PUBLIC_BASE_URL = 'https://pub-5b228bbc9cb1401db657b3a59ae649bf.r2.dev';
$SCANNER_R2_OBJECT_PREFIX = 'scanner';

// Main app image storage: 'local' or 'r2'
$APP_STORAGE_DRIVER = 'r2';

// Cloudflare R2 settings for main app image uploads.
// Defaults reuse the scanner R2 bucket/credentials so all app images live in one bucket.
$APP_R2_ACCOUNT_ID = $SCANNER_R2_ACCOUNT_ID;
$APP_R2_BUCKET = $SCANNER_R2_BUCKET;
$APP_R2_ACCESS_KEY_ID = $SCANNER_R2_ACCESS_KEY_ID;
$APP_R2_SECRET_ACCESS_KEY = $SCANNER_R2_SECRET_ACCESS_KEY;
$APP_R2_PUBLIC_BASE_URL = $SCANNER_R2_PUBLIC_BASE_URL;
$APP_R2_OBJECT_PREFIX = 'main-app';



// Optional: fallback Telegram targets when a seller has no Chat ID in Admin → Users.
// Seller orders use each user's telegram_chat_id + telegram_thread_id first.
// Also used by send_telegram_message() and as fallback for some reports.
// Each item: ['chat_id' => '...', 'thread_id' => null or topic id]
$TELEGRAM_TARGETS = [
    // ['chat_id' => '-1003261380002', 'thread_id' => null],
    ['chat_id' => '-1003261380002', 'thread_id' => 2],
];
if (!isset($TELEGRAM_TARGETS)) {
    $TELEGRAM_TARGETS = [];
}

// Optional: separate Telegram settings for EOD stock reports.
// If left empty, EOD reports will fall back to the global Telegram settings above.
$EOD_TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
$EOD_TELEGRAM_CHAT_ID = '';
$EOD_TELEGRAM_TARGETS = [
    // ['chat_id' => '-1000000000000', 'thread_id' => 10],
    // ['chat_id' => '-1003261380002', 'thread_id' => 2],
    ['chat_id' => ' -1002915548141', 'thread_id' => 96],
    // ['chat_id' => ' -1002915548141', 'thread_id' => 96],
];
if (!isset($EOD_TELEGRAM_TARGETS)) {
    $EOD_TELEGRAM_TARGETS = [];
}

// Optional: separate Telegram settings for EOM stock reports.
// If left empty, EOM reports will fall back to the global Telegram settings above.
$EOM_TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
$EOM_TELEGRAM_CHAT_ID = '';
$EOM_TELEGRAM_TARGETS = [
    // ['chat_id' => '-1000000000000', 'thread_id' => 20],
    ['chat_id' => ' -1002915548141', 'thread_id' => 96],
];
if (!isset($EOM_TELEGRAM_TARGETS)) {
    $EOM_TELEGRAM_TARGETS = [];
}

// Optional: separate Telegram settings for Sold Products reports.
// If left empty, sold products reports will fall back to the global Telegram settings above.
$SOLD_PRODUCTS_TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
$SOLD_PRODUCTS_TELEGRAM_CHAT_ID = '';
$SOLD_PRODUCTS_TELEGRAM_TARGETS = [
    // ['chat_id' => '-1000000000000', 'thread_id' => 30],
    // ['chat_id' => ' -1002751887489', 'thread_id' => 3],
    ['chat_id' => ' -1002915548141', 'thread_id' => 377],
    // ['chat_id' => ' -1003752347988', 'thread_id' => 3],
    // ['chat_id' => ' -1002915548141', 'thread_id' => 96],

];  
if (!isset($SOLD_PRODUCTS_TELEGRAM_TARGETS)) {
    $SOLD_PRODUCTS_TELEGRAM_TARGETS = [];
}


// Optional: separate Telegram settings for Stock Movement notifications.
// If left empty, stock movement notifications will fall back to the global Telegram settings above.
$STOCK_MOVEMENT_TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
$STOCK_MOVEMENT_TELEGRAM_CHAT_ID = '';
$STOCK_MOVEMENT_TELEGRAM_TARGETS = [
    // ['chat_id' => '-1000000000000', 'thread_id' => 40],
    // ['chat_id' => '-1003261380002', 'thread_id' => 2],
    ['chat_id' => ' -1002915548141', 'thread_id' => 2553],
];
if (!isset($STOCK_MOVEMENT_TELEGRAM_TARGETS)) {
    $STOCK_MOVEMENT_TELEGRAM_TARGETS = [];
}

// Optional: separate Telegram settings for Marketing Suggest notifications.
// When a market suggest is created, send notification. Approve/Reconcile done on website.
// If left empty, falls back to global Telegram settings.
$MARKETING_TELEGRAM_BOT_TOKEN = '8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk';
$MARKETING_TELEGRAM_CHAT_ID = '';
$MARKETING_TELEGRAM_TARGETS = [
    // ['chat_id' => '-1000000000000', 'thread_id' => 5],
    ['chat_id' => '-1002915548141', 'thread_id' => 435],
];
if (!isset($MARKETING_TELEGRAM_TARGETS)) {
    $MARKETING_TELEGRAM_TARGETS = [];
}

// Base URL (adjust if you use virtual host or subfolder)
$BASE_URL = '/OrderShadow';
// /OrderShadow

// Absolute domain for frontend JS / external links
// For localhost XAMPP this is fine. Change when you deploy.
$DOMAIN = 'http://localhost' . $BASE_URL;

// API CORS origins allowed to call JSON endpoints with session cookies.
$API_ALLOWED_ORIGINS = [
    'https://muru-report.vercel.app',
    'http://192.168.110.16:5173',
   ];
