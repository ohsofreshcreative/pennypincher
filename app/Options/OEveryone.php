<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class OEveryone extends Options
{
	public $name = 'Dla kogo';
	public $slug = 'oeveryone';
	public $title = 'Dla kogo';
	public $position = 101;
	public $capability = 'edit_posts';
	public $redirect = false;

	public function fields(): FieldsBuilder
	{
		$oeveryone = new FieldsBuilder('oeveryone');

		$oeveryone
			->addGroup('g_everyone', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->endGroup()

			->addRepeater('r_everyone', [
				'label' => 'Zakładki',
				'layout' => 'block', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj zakładkę',
			])
			->addText('tab', [
				'label' => 'Nazwa zakładki',
				'instructions' => 'Tekst widoczny na przycisku przełączającym.',
			])
			->addText('title', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'medium',
			])
			->addRepeater('r_tags', [
				'label' => 'Tagi',
				'layout' => 'table',
				'button_label' => 'Dodaj tag',
			])
			->addText('label', ['label' => 'Nazwa'])
			->endRepeater()
			->endRepeater();

		return $oeveryone;
	}
}
