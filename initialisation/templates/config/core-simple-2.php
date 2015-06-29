<?php

namespace GFPDF\Templates\Config;

use GFPDF\Helper\Helper_Int_Config;

/**
 * core-simple_2.php configuration file
 * This configuration file can be overridden by being placed in the PDF_EXTENDED_TEMPLATES/config/ folder
 * Note: if running a multisite that would be the PDF_EXTENDED_TEMPLATES/:id/config/ folder, where :id is the subsite ID number
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2015, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.0
 *
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
 * The class name should be the exact same as the template file name (with hyphens replaced with underscores)
 * For instance, a template called core-simple.php would have a class of "core_simple"
 * This naming convension is very important, otherwise the software cannot correctly load the configuration
 */
class core_simple_2 implements Helper_Int_Config {

	/**
	 * Return the configuration structure.
	 *
	 * The fields key is based on our \GFPDF\Helper\Helper_Options Settings API
	 * See the register_settings() method for the exact fields that can be passed in
	 * @return Array The array, split into core components and custom fields
	 * @since 4.0
	 */
	public function configuration() {
		return array(
			/*'core' => array(
				'header' => true,
				'firstHeader' => true,
				'footer' => true,
				'background' => true,
			),*/

			'fields' => array(
				'title_background_colour' => array(
					'id'   => 'title_background_colour',
					'name' => __('Title Background Colour', 'gravitypdf'),
					'type' => 'color',
					'desc' => __('Control the background colour of the field titles.', 'gravitypdf'),
					'std'  => '#222222'
				),
			)
		);
	}
}