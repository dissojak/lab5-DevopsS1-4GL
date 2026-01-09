<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;

echo "Checking classes...\n";

if (class_exists('App\Controller\UploadController')) {
    echo "App\Controller\UploadController exists.\n";
} else {
    echo "App\Controller\UploadController DOES NOT exist.\n";
}

if (class_exists('App\Controller\Admin\DashboardController')) {
    echo "App\Controller\Admin\DashboardController exists.\n";
} else {
    echo "App\Controller\Admin\DashboardController DOES NOT exist.\n";
}
