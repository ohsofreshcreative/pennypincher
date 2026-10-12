<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class OAbout extends Options
{
	public $name = 'O nas';
	public $slug = 'oabout';
	public $title = 'O nas';
	public $position = 101;
	public $capability = 'edit_posts';
	public $redirect = false;

	public function fields(): FieldsBuilder
	{
		$oabout = new FieldsBuilder('oabout');

		$oabout
			->addGroup('g_about', ['label' => ''])
			->addText('label', ['label' => 'Etykieta'])
			->addText('header', ['label' => 'Nagłówek'])
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
			->endGroup()

			->addRepeater('r_about', [
				'label' => 'Kafelki',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj kafelek',
			])
			->addImage('icon', [
				'label' => 'Ikonka',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', ['label' => 'Nagłówek'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 3,
				'new_lines' => 'br',
			])
			->endRepeater();

		return $oabout;
	}
}
