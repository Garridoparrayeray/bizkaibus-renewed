<?php

// Engine\<Red>\Clase vive en engine/<red>/Clase.php; el resto, en api/.
spl_autoload_register(function (string $sClass): void {
    $aParts = explode('\\', $sClass);
    if ($aParts[0] === 'Engine' && count($aParts) >= 3) {
        $sClassName = array_pop($aParts);
        array_shift($aParts);
        $sPath = __DIR__ . '/../engine/' . strtolower(implode('/', $aParts)) . '/' . $sClassName . '.php';
    } else {
        $sPath = __DIR__ . '/' . implode('/', $aParts) . '.php';
    }
    if (is_file($sPath)) {
        require $sPath;
    }
});
