<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Logos extends Block
{
    public $name = 'Logotypy partnerów';
    public $description = 'logos';
    public $slug = 'logos';
    public $category = 'formatting';
    public $icon = 'images-alt';
    public $keywords = ['tresc', 'logotypy', 'partnerzy'];
    public $mode = 'edit';
    public $supports = [
        'align' => false,
        'mode' => true,
        'jsx' => true,
        'anchor' => true,
        'customClassName' => true,
    ];

    public function fields()
    {
        $logos = new FieldsBuilder('logos');

        $logos
            ->setLocation('block', '==', 'acf/logos')
            ->addTab('Elementy', ['placement' => 'top'])
            ->addMessage('Informacja', 'Logotypy edytujemy klikając w menu panelu administratora „Logotypy partnerów".')

            /*--- USTAWIENIA BLOKU ---*/

            ->addTab('Ustawienia bloku', ['placement' => 'top'])
            ->addText('section_id', ['label' => 'ID'])
            ->addText('section_class', ['label' => 'Dodatkowe klasy CSS'])
            ->addTrueFalse('flip', [
                'label' => 'Odwrotna kolejność',
                'ui' => 1,
                'ui_on_text' => 'Tak',
                'ui_off_text' => 'Nie',
            ])
            ->addTrueFalse('wide', [
                'label' => 'Szeroka kolumna',
                'ui' => 1,
                'ui_on_text' => 'Tak',
                'ui_off_text' => 'Nie',
            ])
            ->addTrueFalse('nomt', [
                'label' => 'Usunięcie marginesu górnego',
                'ui' => 1,
                'ui_on_text' => 'Tak',
                'ui_off_text' => 'Nie',
            ])
            ->addTrueFalse('gap', [
                'label' => 'Większy odstęp',
                'ui' => 1,
                'ui_on_text' => 'Tak',
                'ui_off_text' => 'Nie',
            ])
            ->addSelect('background', [
                'label' => 'Kolor tła',
                'choices' => SectionBackgrounds::choices(),
                'default_value' => 'none',
                'ui' => 0,
                'allow_null' => 0,
            ]);

        return $logos;
    }

    public function with(): array
    {
        $fields = [
            'g_logos' => get_field('g_logos', 'option'),  // <-- z Options page

            'section_id' => get_field('section_id'),
            'section_class' => get_field('section_class'),

            'flip' => (bool) get_field('flip'),
            'wide' => (bool) get_field('wide'),
            'nomt' => (bool) get_field('nomt'),
            'gap' => (bool) get_field('gap'),

            'background' => get_field('background') ?: 'none',
        ];

        $fields['sectionClass'] = SectionClasses::fromMap($fields, [
            'flip' => 'order-flip',
            'wide' => 'wide',
            'nomt' => '!mt-0',
            'gap' => 'wider-gap',
        ]);

        return $fields;
    }
}
