<?php

spl_autoload_register(function (string $sClass): void {
    $sRelative = str_replace('\\', '/', $sClass) . '.php';
    if (str_starts_with($sRelative, 'Engine/')) {
        $sPath = __DIR__ . '/../engine/' . substr($sRelative, strlen('Engine/'));
    } else {
        $sPath = __DIR__ . '/' . $sRelative;
    }
    if (is_file($sPath)) {
        require $sPath;
    }
});
