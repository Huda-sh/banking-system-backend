<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Mail;

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Mail::raw('This is a test email from the banking system backend.', function($message) {
    $message->to('mhranabwdqt971@gmail.com')->subject('Test Email from Banking System');
});

echo "Test email sent to mhranabwdqt971@gmail.com\n";
