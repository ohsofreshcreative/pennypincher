<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class Octa extends Options
{
	public $name = 'Wezwanie do działania';
	public $slug = 'octa';
	public $title = 'Wezwanie do działania';
	public $position = 101;
	public $capability = 'edit_posts';
	public $redirect = false;

	public function fields(): FieldsBuilder
	{
		$octa = new FieldsBuilder('octa');

		$octa
			->addGroup('g_octa', ['label' => ''])
			->addImage('image', [
				'label'         => 'Obraz tła',
				'return_format' => 'array',
				'preview_size'  => 'medium',
			])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('txt', [
				'label'        => 'Treść',
				'tabs'         => 'visual',
				'toolbar'      => 'basic',
				'media_upload' => false,
			])
			->addLink('button1', [
				'label'         => 'Przycisk #1 (jasny)',
				'return_format' => 'array',
			])
			->addLink('button2', [
				'label'         => 'Przycisk #2 (biały)',
				'return_format' => 'array',
			])
			->addText('title', ['label' => 'Tytuł'])
			->addText('shortcode', [
				'label' => 'Kod formularza',
				'instructions' => 'Wklej kod formularza:  [contact-form-7 id="f12c470" title="Contact form 1"]',
			])
			->endGroup();

		return $octa;
	}
}
