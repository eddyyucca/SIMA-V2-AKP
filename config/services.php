<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    // System Requisition: notifikasi request baru (perlu approval) ke grup Telegram tim IT. Kosongkan = tidak dikirim.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],


    // HRIS (sumber data karyawan, REST baca-saja) dan token layanan untuk aplikasi lain yang membaca aplikasi ini.
    'hris' => [
        'url' => env('HRIS_API_URL'),
        'token' => env('HRIS_API_TOKEN'),
        'cache_ttl' => (int) env('HRIS_CACHE_TTL', 300),
    ],
    // Token yang dipakai HRIS untuk membaca aset karyawan (sama dengan SIMA_API_TOKEN di .env HRIS). Kosong = API integrasi mati.
    'integration_token' => env('SIMA_API_TOKEN'),

];
