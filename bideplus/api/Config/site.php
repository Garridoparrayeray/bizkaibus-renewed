<?php

return [
    // La dirección de la app: la canónica, el sitemap, las vistas previas y las comprobaciones de producción salen de
    // aquí. bideplus.vercel.app y bideplus.eus redirigen a ella.
    'url' => 'https://www.bideplus.eus',
    'name' => 'Bide+',
    'locale' => 'es_ES',
    // La red que se abre en «/» y cuyas direcciones no llevan ?red= (una de api/Networks).
    'default_network' => 'bus',
    // Días que una red nueva (added en su ficha) o una función nueva (data-new-since) llevan la etiqueta «Nuevo».
    'new_days' => 30,
    'author' => 'Yeray Garrido',
    'author_url' => 'https://www.yeraygarrido.dev/',
    // Enlaces bajo «Hecho por», en el pie y en Info.
    'author_links' => [
        'LinkedIn' => 'https://www.linkedin.com/in/yeray-garrido',
        'Portfolio' => 'https://www.yeraygarrido.dev/',
    ],
    // El enlace «Código en GitHub».
    'source_url' => 'https://github.com/Garridoparrayeray/bizkaibus-renewed',
    // Adonde llegan las sugerencias y los errores del formulario de Info.
    'contact_email' => 'garridoparrayeraytx@gmail.com',
    // La página de Ko-fi de «Apoya Bide+» en Info. Vacía, la tarjeta no sale.
    'donate_url' => 'https://ko-fi.com/bideplus',
    'gsc_verification' => 'd7akLe3xucjSHfxtm75cUFm5NyeE8X2NEX3f8knOGYU',
];
