<?php

namespace App\Walkers;

use Walker_Nav_Menu;

class DropdownWalker extends Walker_Nav_Menu
{
    /**
     * Starts the list before the elements are added.
     */
    public function start_lvl(&$output, $depth = 0, $args = null)
    {
        $classes = 'absolute z-10 -mt-2 w-max origin-top-right bg-white border-primary shadow-2xl focus:outline-none';
        
        $output .= "<ul x-show=\"open\" @click.away=\"open = false\" x-transition:enter=\"transition ease-out duration-200\" x-transition:enter-start=\"opacity-0 transform -translate-y-2\" x-transition:enter-end=\"opacity-100 transform translate-y-0\" x-transition:leave=\"transition ease-in duration-150\" x-transition:leave-start=\"opacity-100 transform translate-y-0\" x-transition:leave-end=\"opacity-0 transform -translate-y-2\" class=\"{$classes} rounded-xl p-2\" style=\"display: none;\">";
    }

    /**
     * Starts the element output.
     */
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
    {
        $has_children = in_array('menu-item-has-children', $item->classes);
        $li_classes = empty($item->classes) ? '' : ' class="' . esc_attr(implode(' ', $item->classes)) . '"';

        // Case 1: Element jest na najwyższym poziomie i ma dzieci (jest dropdownem)
        if ($depth === 0 && $has_children) {
            // Logika Alpine.js do otwierania/zamykania przy hover zostaje w <li>
            $output .= '<li x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" @focusin="open = true" @focusout="open = false" class="relative ' . esc_attr(implode(' ', $item->classes)) . '">';

            // ### POCZĄTEK ZMIANY ###
            // Zamiast <button> używamy <a> z linkiem do strony nadrzędnej.
            // Usunęliśmy `@click`, aby kliknięcie powodowało standardową nawigację.
            $output .= '<a href="' . esc_attr($item->url) . '" class="inline-flex items-center gap-x-1 text-sm! xl:text-base! font-medium hover:text-indigo-600" aria-haspopup="true" :aria-expanded="open.toString()">';
            $output .= esc_html($item->title);
            $output .= '<svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>';
            $output .= '</a>';
            // ### KONIEC ZMIANY ###

        }
        // Case 2: Pozostałe elementy (zwykłe linki na górze lub linki wewnątrz dropdownu)
        else {
            $output .= '<li' . $li_classes . '>';

            $link_classes = '';
            if ($depth > 0) {
                $link_classes = 'block rounded-xl px-6 py-4 text-sm md:text-base text-gray-700 hover:bg-primary hover:!text-white';
            } else {
                $link_classes = 'text-sm xl:text-base! font-medium! hover:text-indigo-600';
            }

            $output .= '<a href="' . esc_attr($item->url) . '" class="' . esc_attr($link_classes) . '">';
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