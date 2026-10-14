<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:manager-library', function () {
    $this->info('Manager Library API');
});
