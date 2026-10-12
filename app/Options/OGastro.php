<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class OGastro extends Options
{
	public $name = 'Gastronomia';
	public $slug = 'ogastro';
	public $title = 'Gastronomia';
	public $position = 101;
	public $capability = 'edit_posts';
	public $redirect = false;

	public function fields(): FieldsBuilder
	{
		$ogastro = new FieldsBuilder('ogastro');

		$ogastro
			->addGroup('g_gastro', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->endGroup()

			->addRepeater('r_gastro', [
				'label' => 'Kafelki',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj kafelek',
			])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', ['label' => 'Nagłówek'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 5,
				'new_lines' => 'br',
			])
			->endRepeater();

		return $ogastro;
	}
}
