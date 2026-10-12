<?php

namespace App\Options;

use Log1x\AcfComposer\Options;
use StoutLogic\AcfBuilder\FieldsBuilder;

class OPrices extends Options
{
	public $name = 'Cennik';
	public $slug = 'oprices';
	public $title = 'Cennik';
	public $position = 101;
	public $capability = 'edit_posts';
	public $redirect = false;

	public function fields(): FieldsBuilder
	{
		$oprices = new FieldsBuilder('oprices');

		$oprices
			->addRepeater('r_price_tabs', [
				'label' => 'Zakładki',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj zakładkę',
			])
			->addText('tab', ['label' => 'Nazwa zakładki'])
			->addRepeater('r_tables', [
				'label' => 'Tabele cen',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj tabelę',
			])
			->addText('header', ['label' => 'Nagłówek tabeli'])
			->addRepeater('r_rows', [
				'label' => 'Wiersze',
				'layout' => 'row',
				'min' => 1,
				'button_label' => 'Dodaj wiersz',
			])
			->addText('label', ['label' => 'Nazwa'])
			->addText('price', ['label' => 'Cena'])
			->endRepeater()
			->endRepeater()
			->endRepeater()

			->addText('groups_header', [
				'label' => 'Nagłówek sekcji grup',
				'default_value' => 'Wejścia dla grup zorganizowanych (min. 15 osób)',
			])
			->addRepeater('r_groups', [
				'label' => 'Cenniki dla grup zorganizowanych',
				'layout' => 'block',
				'min' => 0,
				'button_label' => 'Dodaj cennik',
			])
			->addText('header', ['label' => 'Nagłówek tabeli'])
			->addRepeater('r_rows', [
				'label' => 'Wiersze',
				'layout' => 'row',
				'min' => 1,
				'button_label' => 'Dodaj wiersz',
			])
			->addText('label', ['label' => 'Nazwa'])
			->addText('price', ['label' => 'Cena'])
			->endRepeater()
			->addText('note', ['label' => 'Uwaga pod tabelą'])
			->endRepeater();

		return $oprices;
	}
}
