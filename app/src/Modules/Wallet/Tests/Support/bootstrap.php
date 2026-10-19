<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Support;

require_once dirname(__DIR__, 5).'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 5).'/.env');
