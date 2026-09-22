<?php

return str_replace(
    '<svg ',
    '<svg role="img" aria-label="Bide+" class="wordmark" ',
    file_get_contents(__DIR__ . '/../../icons-bide-rojo/bide-wordmark-mayusculas.svg')
);
