<?php

namespace App\Walkers;

use Walker_Nav_Menu;

class MobileDropdownWalker extends Walker_Nav_Menu
{
    private $current_item_url;
    private $current_submenu_id;

    /**
     * Starts the list before the elements are added.
     */
    public function start_lvl(&$output, $depth = 0, $args = null)
    {
        $submenu_id = $this->current_submenu_id;

        // Dodajemy link do strony nadrzędnej jako pierwszy element podmenu
        if ($depth === 0 && isset($this->current_item_url)) {
            $output .= "\n<ul id=\"{$submenu_id}\" x-show=\"open\" x-transition class=\"pl-4 mt-2 space-y-2\" style=\"display: none;\">\n";
            $output .= '<li><a href="' . esc_attr($this->current_item_url) . '" class="block py-1 font-semibold">Zobacz wszystko</a></li>';
            unset($this->current_item_url); // Czyścimy właściwość po użyciu
        } else {
            // Submenu jest domyślnie ukryte i pojawia się z animacją.
            $output .= "\n<ul id=\"{$submenu_id}\" x-show=\"open\" x-transition class=\"pl-4 mt-2 space-y-2\" style=\"display: none;\">\n";
        }
    }

    /**
     * Starts the element output.
     */
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
    {
        $has_children = in_array('menu-item-has-children', $item->classes);
        
        // Zapisujemy URL elementu nadrzędnego, aby użyć go w start_lvl
        if ($has_children) {
            $this->current_item_url = $item->url;
        }

        // Case 1: Element ma dzieci (submenu).
        if ($has_children) {
            $submenu_id = 'submenu-' . $item->ID;
            $this->current_submenu_id = $submenu_id;

            $output .= '<li x-data="{ open: false }">';

            // Używamy klas `block py-1` dla spójności, dodając `relative` do pozycjonowania strzałki
            $output .= '<button @click="open = !open" class="block w-full py-1 text-left relative" aria-haspopup="true" :aria-expanded="open.toString()" aria-controls="' . esc_attr($submenu_id) . '">';
            $output .= '<span class="!text-white !text-xl hover:!text-primary-400">' . esc_html($item->title) . '</span>';
            
            // Pozycjonujemy strzałkę absolutnie wewnątrz przycisku
            $output .= '<svg class="w-5 h-5 text-primary-light transition-transform duration-200 shrink-0 absolute top-1/2 right-0 -translate-y-1/2" :class="{ \'rotate-180\': open }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>';
            $output .= '</button>';
        } 
        // Case 2: Zwykły element menu, bez dzieci.
        else {
            $output .= '<li>';
            $output .= '<a href="' . esc_attr($item->url) . '" class="block py-1">';
            $output .= esc_html($item->title);
            $output .= '</a>';
        }
    }

    /**
     * Ends the element output, closing `<li>`.
     */
    public function end_el(&$output, $item, $depth = 0, $args = null)
    {
        $output .= "</li>\n";
    }

    /**
     * Ends the list of after the elements are added.
     */
    public function end_lvl(&$output, $depth = 0, $args = null)
    {
        $output .= "</ul>\n";
    }
}