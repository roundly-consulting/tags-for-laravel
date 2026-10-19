<?php

declare(strict_types=1);

return [
    'list' => 'Pole :attribute musí byť zoznam štítkov.',
    'invalid' => 'Pole :attribute obsahuje neplatný štítok.',
    'not_found' => 'Pole :attribute obsahuje štítok, ktorý neexistuje.',
    'not_allowed' => 'Pole :attribute nemožno použiť pre tento záznam.',
    'max' => '{1} Pole :attribute môže obsahovať len jeden štítok.|[2,4] Pole :attribute môže obsahovať najviac :max štítky.|[0,*] Pole :attribute môže obsahovať najviac :max štítkov.',

    'attributes' => [
        'name' => 'názov',
        'description' => 'popis',
        'slug' => 'slug',
        'color' => 'farba',
        'icon' => 'ikona',
        'parent' => 'nadradený štítok',
        'position' => 'pozícia',
        'order' => 'poradie',
        'children' => 'potomkovia',
        'q' => 'hľadaný výraz',
        'limit' => 'limit',
        'on' => 'typ záznamu',
    ],
];
