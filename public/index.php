<?php

use App\Kernel;
use Doctrine\Deprecations\Deprecation;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
Deprecation::ignoreDeprecations();

return fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
