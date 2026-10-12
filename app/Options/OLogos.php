<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class OLogos extends Options
{
    public $name = 'Logotypy partnerów';
    public $slug = 'ologos';
    public $title = 'Logotypy partnerów';
    public $position = 102;
    public $capability = 'edit_posts';
    public $redirect = false;

    public function fields(): FieldsBuilder
    {
        $logos = new FieldsBuilder('ologos');

        $logos
            ->addGroup('g_logos', ['label' => ''])
            ->addText('header', ['label' => 'Tytuł'])
            ->addGallery('gallery', [
                'label'        => 'Logotypy',
                'preview_size' => 'thumbnail',
                'library'      => 'all',
                'min'          => 1,
            ])
            ->endGroup();

        return $logos;
    }
}