<?php

namespace App\Fields;

use Log1x\AcfComposer\Field;
use StoutLogic\AcfBuilder\FieldsBuilder;

class PostFields extends Field
{
	public function fields(): array
	{
		$post = new FieldsBuilder('post_fields', [
			'title'    => 'Ustawienia wpisu',
			'style'    => 'seamless',
			'position' => 'side',
		]);

		$post
			->setLocation('post_type', '==', 'post')
			->addImage('events_card_background', [
				'label'         => 'Grafika tła kafelka wydarzeń',
				'return_format' => 'array',
				'preview_size'  => 'medium',
				'allow_null'    => 1,
			])
			->addText('events_card_title', [
				'label'         => 'Nagłówek kafelka wydarzeń',
				'default_value' => 'Sprawdź aktualne wydarzenia',
			])
			->addWysiwyg('events_card_text', [
				'label'         => 'Treść kafelka wydarzeń',
				'tabs'          => 'all',
				'toolbar'       => 'full',
				'media_upload'  => true,
				'default_value' => 'Sprawdź nadchodzące wydarzenia i znajdź coś dla siebie.',
			])
			->addLink('events_card_button', [
				'label'         => 'Przycisk kafelka wydarzeń',
				'return_format' => 'array',
			]);

		return [$post];
	}
}