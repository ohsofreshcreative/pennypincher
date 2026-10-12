<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class Archive extends Composer
{
    protected static $views = [
        'archive',
        'category',
        'tag',
    ];

    public function with()
    {
        return [
            'category_image' => $this->categoryImage(),
        ];
    }

    public function categoryImage()
    {
        if (! is_category()) {
            return false;
        }

        $term = get_queried_object();

        if (isset($term->term_id)) {
            $term_id_string = 'category_' . $term->term_id;
            return get_field('category_image', $term_id_string);
        }

        return false;
    }
}