<?php

declare(strict_types=1);

return [
    'list' => 'The :attribute must be a list of tags.',
    'invalid' => 'The :attribute contains an invalid tag.',
    'not_found' => 'The :attribute contains a tag that does not exist.',
    'not_allowed' => 'The :attribute cannot be used on this record.',
    'max' => '{1} The :attribute may hold only one tag.|[0,*] The :attribute may hold at most :max tags.',

    'attributes' => [
        'name' => 'name',
        'description' => 'description',
        'slug' => 'slug',
        'color' => 'colour',
        'icon' => 'icon',
        'parent' => 'parent tag',
        'position' => 'position',
        'order' => 'order',
        'children' => 'children',
        'q' => 'search term',
        'limit' => 'limit',
        'on' => 'record type',
    ],
];
