<?php

namespace GFPDF\Helper\Fields;

use GFPDF\Helper\Helper_Fields;
use GFFormsModel;
use Exception;

/**
 * Gravity Forms Field
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2015, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.0
 */

/* Exit if accessed directly */
if (! defined('ABSPATH')) {
    exit;
}

/*
    This file is part of Gravity PDF.

    Gravity PDF Copyright (C) 2015 Blue Liquid Designs

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/**
 * Controls the display and output of a Gravity Form field
 *
 * @since 4.0
 */
class Field_Likert extends Helper_Fields
{

    /**
     * Display the HTML version of this field
     * @return String
     * @since 4.0
     */
    public function html() {
        $value = apply_filters('gform_entry_field_value', $this->get_value(), $this->field, $this->entry, $this->form);

        return parent::html($value);
    }

    /**
     * Get the standard GF value of this field
     * @return String/Array
     * @since 4.0
     */
    public function value() {
        if($this->has_cache()) {
            return $this->cache();
        }

        /*
         * Process Single and Multi Column Likerts
         */
        $likert = array();

        /*
         * Get the column names
         */
        foreach($this->field->choices as $column) {
            $likert['col'][$column['value']] = $column['text'];
        }

        /**
         * Build our Likert Array
         */
        if(is_array($this->field->inputs) && sizeof($this->field->inputs) > 0) { /* Handle our multirow likert */

            /* loop through each row */
            foreach($this->field->inputs as $row) {
                /* loop through each column */
                foreach($likert['col'] as $id => $text) {
                    /* check if user selected this likert value */
                    $data = rgar($this->entry, $row['id']);

                    $likert['rows'][$row['label']][$text] = ( ($row['name'] . ':' . $id) == $data) ? 'selected' : '';
                }
            }
            
        } else { /* Handle our single-row likert */

            /* Get the value from the entry */
            $data = rgar($this->entry, $this->field->id);
            foreach($likert['col'] as $id => $text) {
                /* check if user selected this likert value */
                $likert['row'][$text] = ($id == $data) ? 'selected' : '';
            }
        }

        $this->cache($likert);
        
        return $this->cache();
    }
}