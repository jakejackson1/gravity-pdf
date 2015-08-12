<?php

namespace GFPDF\Model;

use GFPDF\Model\Model_Form_Settings;
use GFPDF\Helper\Helper_Abstract_Model;
use GFPDF\Helper\Helper_PDF;

use GFPDF\Helper\Helper_Abstract_Fields;
use GFPDF\Helper\Fields\Field_Product;
use GFPDF\Helper\Fields\Field_Default;
use GFPDF\Helper\Fields\Field_Products;

use GFFormsModel;
use GFCommon;
use GF_Field;
use GFQuiz;
use GFSurvey;
use GFPolls;
use GFResults;

use WP_Error;

use Exception;

/**
 * PDF Display Model, including the $form_data array
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2015, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.0
 */

/* Exit if accessed directly */
if ( ! defined( 'ABSPATH' ) ) {
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
 * Model_PDF
 *
 * Handles all the PDF display logic
 *
 * @since 4.0
 */
class Model_PDF extends Helper_Abstract_Model {

	/**
	 * Our Middleware used to handle the authentication process
	 * @param  $pid The Gravity Form PDF Settings ID
	 * @param  $lid The Gravity Form Entry ID
	 * @param  $action Whether the PDF should be viewed or downloaded
	 * @since 4.0
	 * @return void
	 */
	public function process_pdf( $pid, $lid, $action = 'view' ) {
		global $gfpdf;

		/**
		 * Check if we have a valid Gravity Form Entry and PDF Settings ID
		 */
		$entry = $gfpdf->form->get_entry( $lid );

		/* not a valid entry */
		if ( is_wp_error( $entry ) ) {
			return $entry; /* return error */
		}

		$settingsAPI = new Model_Form_Settings();
		$settings = $settingsAPI->get_pdf( $entry['form_id'], $pid );

		/* Add our download setting */
		$settings['pdf_action'] = $action;

		/* Not valid settings */
		if ( is_wp_error( $settings ) ) {
			return $settings; /* return error */
		}

		/**
		 * Our middleware authenticator
		 * Allow users to tap into our middleware and add additional or remove additional authentication layers
		 *
		 * Default middleware includes 'middle_logged_out_restriction', 'middle_logged_out_timeout', 'middle_auth_logged_out_user', 'middle_user_capability'
		 * If WP_Error is returned the PDF won't be parsed
		 */
		$middleware = apply_filters( 'gfpdf_pdf_middleware', false, $entry, $settings );

		/* Throw error */
		if ( is_wp_error( $middleware ) ) {
			return $middleware;
		}

		/**
		 * If we are here we can generate our PDF
		 */
		$controller = $this->getController();
		$controller->view->generate_pdf( $entry, $settings );
	}

	/**
	 * Check if the current user attempting to access is the PDF owner
	 * @param  Array  $entry    The Gravity Forms Entry
	 * @param  String $type    The authentication type we should use
	 * @return Boolean
	 * @since 4.0
	 */
	public function is_current_pdf_owner( $entry, $type = 'all' ) {
		$owner = false;
		/* check if the user is logged in and the entry is assigned to them */
		if ( $type === 'all' || $type === 'logged_in' ) {
			if ( is_user_logged_in() && (int) $entry['created_by'] === get_current_user_id() ) {
				$owner = true;
			}
		}

		if ( $type === 'all' || $type === 'logged_out' ) {
			$user_ip = trim( GFFormsModel::get_ip() );
			if ( $entry['ip'] == $user_ip && $entry['ip'] !== '127.0.0.1' && strlen( $user_ip ) !== 0 ) { /* check if the user IP matches the entry IP */
				$owner = true;
			}
		}

		return $owner;
	}

	/**
	 * Check the "Restrict Logged Out User" global setting and validate it against the current user
	 * @param  Boolean / Object $action
	 * @param  Array            $entry    The Gravity Forms Entry
	 * @param  Array            $settings The Gravity Form PDF Settings
	 * @return Boolean / Object
	 * @since 4.0
	 */
	public function middle_logged_out_restriction( $action, $entry, $settings ) {
		global $gfpdf;

		/* ensure another middleware filter hasn't already done validation */
		if ( ! is_wp_error( $action ) ) {
			/* get the setting */
			$logged_out_restriction = $gfpdf->options->get_option( 'limit_to_admin', 'No' );

			if ( $logged_out_restriction === 'Yes' && ! is_user_logged_in() ) {
				/* prompt user to login */
				auth_redirect();
			}
		}

		return $action;
	}

	/**
	 * Check the "Logged Out Timeout" global setting and validate it against the current user
	 * @param  Boolean / Object $action
	 * @param  Array            $entry    The Gravity Forms Entry
	 * @param  Array            $settings The Gravity Form PDF Settings
	 * @return Boolean / Object
	 * @since 4.0
	 */
	public function middle_logged_out_timeout( $action, $entry, $settings ) {
		global $gfpdf;

		/* ensure another middleware filter hasn't already done validation */
		if ( ! is_wp_error( $action ) ) {

			/* only check if PDF timed out if our logged out restriction is not 'Yes' and the user is not logged in */
			if ( ! is_user_logged_in() && $this->is_current_pdf_owner( $entry, 'logged_out' ) === true ) {
				/* get the global PDF settings */
				$timeout                = (int) $gfpdf->options->get_option( 'logged_out_timeout', '30' );

				/* if '0' there is no timeout, or if the logged out restrictions are enabled we'll ignore this */
				if ( $timeout !== 0 ) {

					$timeout_stamp   = 60 * $timeout; /* 60 seconds multiplied by number of minutes */
					$entry_created   = strtotime( $entry['date_created'] ); /* get entry timestamp */
					$timeout_expires = $entry_created + $timeout_stamp; /* get the timeout expiry based on the entry created time */

					/* compare our two timestamps and throw error if outside the timeout */
					if ( time() > $timeout_expires ) {

						/* if there is no user account assigned to this entry throw error */
						if ( empty($entry['created_by']) ) {
							return new WP_Error( 'timeout_expired', __( 'Your PDF is no longer accessible.', 'gravitypdf' ) );
						} else {
							/* prompt to login */
							auth_redirect();
						}
					}
				}
			}
		}
		return $action;
	}

	/**
	 * Check if the user is logged out and authenticate as needed
	 * @param  Boolean / Object $action
	 * @param  Array            $entry    The Gravity Forms Entry
	 * @param  Array            $settings The Gravity Form PDF Settings
	 * @return Boolean / Object
	 * @since 4.0
	 */
	public function middle_auth_logged_out_user( $action, $entry, $settings ) {
		if ( ! is_wp_error( $action ) ) {

			/* check if the user is not the current entry owner */
			if ( ! is_user_logged_in() && $this->is_current_pdf_owner( $entry, 'logged_out' ) === false ) {
				/* check if there is actually a user who owns entry */
				if ( ! empty($entry['created_by']) ) {
					/* prompt user to login to get access */
					auth_redirect();
				} else {
					/* there's no returning, throw generic error */
					return new WP_Error( 'error' );
				}
			}
		}
		return $action;
	}

	/**
	 * Check the "User Restriction" global setting and validate it against the current user
	 * @param  Boolean / Object $action
	 * @param  Array            $entry    The Gravity Forms Entry
	 * @param  Array            $settings The Gravity Form PDF Settings
	 * @return Boolean / Object
	 * @since 4.0
	 */
	public function middle_user_capability( $action, $entry, $settings ) {
		global $gfpdf;

		if ( ! is_wp_error( $action ) ) {
			/* check if the user is logged in but is not the current owner */
			if ( is_user_logged_in() &&
				( ($gfpdf->options->get_option( 'limit_to_admin', 'No' ) == 'Yes') || ($this->is_current_pdf_owner( $entry, 'logged_in' ) === false)) ) {
				/* Handle permissions checks */
				 $admin_permissions = $gfpdf->options->get_option( 'admin_capabilities', array( 'gravityforms_view_entries' ) );

				 /* loop through permissions and check if the current user has any of those capabilities */
				 $access = false;
				foreach ( $admin_permissions as $permission ) {
					if ( $gfpdf->form->has_capability( $permission ) ) {
						$access = true;
					}
				}

				/* throw error if no access granted */
				if ( ! $access ) {
					return new WP_Error( 'access_denied', __( 'You do not have access to view this PDF.', 'gravitypdf' ) );
				}
			}
		}
		return $action;
	}

	/**
	 * Display PDF on Gravity Form entry list page
	 * @param  Integer $form_id  Gravity Form ID
	 * @param  Integer $field_id Current field ID
	 * @param  Mixed   $value    Current value of field
	 * @param  Array   $entry     Entry Information
	 * @return void
	 * @since 4.0
	 */
	public function view_pdf_entry_list( $form_id, $field_id, $value, $entry ) {
		global $gfpdf;

		$controller = $this->getController();

		/* Check if we have any PDFs */
		$form = $gfpdf->form->get_form( $entry['form_id'] );
		$pdfs = (isset($form['gfpdf_form_settings'])) ? $this->get_active_pdfs( $form['gfpdf_form_settings'], $entry ) : array();

		if ( ! empty($pdfs) ) {

			$download = ($gfpdf->options->get_option( 'default_action' ) == 'Download') ? 'download/' : '';

			if ( sizeof( $pdfs ) > 1 ) {
				$args = array( 'pdfs' => array() );

				foreach ( $pdfs as $pdf ) {
					$args['pdfs'][] = array(
						'name' => $this->get_pdf_name( $pdf, $entry ),
						'url' => $this->get_pdf_url( $pdf['id'], $entry['id'] ) . $download,
					);
				}

				$controller->view->entry_list_pdf_multiple( $args );
			} else {
				/* Only one PDF for this form so display a simple 'View PDF' link */
				$pdf = array_shift( $pdfs );

				$args = array(
					'url' => $this->get_pdf_url( $pdf['id'], $entry['id'] ) . $download,
				);

				$controller->view->entry_list_pdf_single( $args );
			}
		}
	}

	/**
	 * Display the PDF links on the entry detailed section of the admin area
	 * @param  Integer $form_id Gravity Form ID
	 * @param  Array   $entry    The entry information
	 * @return void
	 * @since  4.0
	 */
	public function view_pdf_entry_detail( $form_id, $entry ) {
		global $gfpdf;

		$controller = $this->getController();

		/* Check if we have any PDFs */
		$form = $gfpdf->form->get_form( $entry['form_id'] );
		$pdfs = (isset($form['gfpdf_form_settings'])) ? $this->get_active_pdfs( $form['gfpdf_form_settings'], $entry ) : array();

		if ( ! empty($pdfs) ) {
			$args = array( 'pdfs' => array() );

			foreach ( $pdfs as $pdf ) {
				$args['pdfs'][] = array(
					'name' => $this->get_pdf_name( $pdf, $entry ),
					'url' => $this->get_pdf_url( $pdf['id'], $entry['id'] ),
				);
			}

			$controller->view->entry_detailed_pdf( $args );
		}
	}

	/**
	 * Generate the PDF Name
	 * @param  Array $pdf  The PDF Form Settings
	 * @param  Array $entry The Gravity Form entry details
	 * @return String      The PDF Name
	 * @since  4.0
	 */
	public function get_pdf_name( $pdf, $entry ) {
		global $gfpdf;

		$form = $gfpdf->form->get_form( $entry['form_id'] );
		$name = GFCommon::replace_variables( $pdf['filename'], $form, $entry );

		/* add filter to modify PDF name */
		$name = apply_filters( 'gfpdf_pdf_filename', $name, $form, $entry, $pdf );
		$name = apply_filters( 'gfpdfe_pdf_filename', $name, $form, $entry, $pdf ); /* backwards compat */

		return $name;
	}

	/**
	 * Create a PDF Link based on the current PDF settings and entry
	 * @param  Integer $pid  The PDF Form Settings ID
	 * @param  Integer $id The Gravity Form entry ID
	 * @return String       Direct link to the PDF
	 * @since  4.0
	 */
	public function get_pdf_url( $pid, $id, $esc = true ) {
		$url = home_url() . '/pdf/' . $pid . '/' . $id . '/';

		if ( $esc ) {
			$url = esc_url( $url );
		}

		return $url;
	}

	/**
	 * Filter out inactive PDFs and those who don't meet the conditional logic
	 * @param  Array $pdfs The PDF settings array
	 * @param  Array $entry The current entry information
	 * @return Array       The filtered PDFs
	 * @since 4.0
	 */
	public function get_active_pdfs( $pdfs, $entry ) {
		global $gfpdf;
		
		$filtered = array();
		$form     = $gfpdf->form->get_form( $entry['form_id'] );

		foreach ( $pdfs as $pdf ) {
			if ( $pdf['active'] && GFCommon::evaluate_conditional_logic( $pdf['conditionalLogic'], $form, $entry ) ) {
				$filtered[$pdf['id']] = $pdf;
			}
		}

		return $filtered;
	}

	/**
	 * Generate and save PDF to disk
	 * @param  Object $pdf The Helper_PDF object
	 * @return Boolean
	 * @since 4.0
	 */
	public function process_and_save_pdf( $pdf ) {
		global $gfpdf;

		/* Check that the PDF hasn't already been created this session */
		if ( $this->does_pdf_exist( $pdf ) ) {
			try {
				$pdf->init();
				$pdf->renderHtml( $gfpdf->misc->get_template_args( $pdf->getEntry(), $pdf->getSettings() ) );
				$pdf->setOutputType( 'save' );

				/* Generate PDF */
				$raw_pdf  = $pdf->generate();
				$pdf->savePdf( $raw_pdf );

				return true;
			} catch (Exception $e) {
				/* Log Error */
				return false;
			}
		}
	}

	/**
	 * Check if the form has any PDFs, generate them and attach to the notification
	 * @param  Array $notifications Gravity Forms Notification Array
	 * @param  Array $form
	 * @param  Array $entry
	 * @return Array
	 * @since 4.0
	 */
	public function notifications( $notifications, $form, $entry ) {
		$pdfs       = (isset($form['gfpdf_form_settings'])) ? $this->get_active_pdfs( $form['gfpdf_form_settings'], $entry ) : array();

		if ( sizeof( $pdfs ) > 0 ) {
			/* set up classes */
			$controller  = $this->getController();
			$settingsAPI = new Model_Form_Settings();

			/* loop through each PDF config and generate */
			foreach ( $pdfs as $pdf ) {
				$settings = $settingsAPI->get_pdf( $entry['form_id'], $pdf['id'] );

				if ( ! is_wp_error( $settings ) && $this->maybe_attach_to_notification( $notifications, $settings ) ) {
					$pdf = new Helper_PDF( $entry, $settings );

					if ( $this->process_and_save_pdf( $pdf ) ) {
						$pdf_path = $pdf->get_path() . $pdf->get_filename();

						if ( is_file( $pdf_path ) ) {
							$notifications['attachments'][] = $pdf_path;
						}
					}
				}
			}
		}
		return $notifications;
	}

	/**
	 * Determine if the PDF should be attached to the current notification
	 * @param  Array $notification The Gravity Form Notification currently being processed
	 * @param  Array $settings     The current Gravity PDF Settings
	 * @return Boolean
	 * @since 4.0
	 */
	public function maybe_attach_to_notification( $notification, $settings ) {
		if ( isset($settings['notification']) && is_array( $settings['notification'] ) ) {
			if ( isset($notification['isActive']) && $notification['isActive'] && in_array( $notification['name'], $settings['notification'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if the PDF should be saved to disk
	 * @param  Array $settings     The current Gravity PDF Settings
	 * @return Boolean
	 * @since 4.0
	 */
	public function maybe_always_save_pdf( $settings ) {
		if ( strtolower( $settings['save'] ) == 'yes' ) {
			return true;
		}

		return false;
	}

	/**
	 * Creates a PDF on every submission, except when the PDF is already created during the notification hook
	 * @param  Array $entry The GF Entry Details
	 * @param  Array $form  The Gravity Form
	 * @return void
	 * @since 4.0
	 */
	public function maybe_save_pdf( $entry, $form ) {
		$pdfs = (isset($form['gfpdf_form_settings'])) ? $this->get_active_pdfs( $form['gfpdf_form_settings'], $entry ) : array();

		if ( sizeof( $pdfs ) > 0 ) {
			/* set up classes */
			$controller  = $this->getController();
			$settingsAPI = new Model_Form_Settings();

			/* loop through each PDF config */
			foreach ( $pdfs as $pdf ) {
				$settings = $settingsAPI->get_pdf( $entry['form_id'], $pdf['id'] );

				/* Only generate if the PDF wasn't created during the notification process */
				if ( ! is_wp_error( $settings ) ) {
					$pdf = new Helper_PDF( $entry, $settings );

					/* Check that the PDF hasn't already been created this session */
					if ( $this->maybe_always_save_pdf( $settings ) ) {
						$this->process_and_save_pdf( $pdf );
					}

					/* Run an action users can tap into to manipulate the PDF */
					if ( $this->does_pdf_exist( $pdf ) ) {
						do_action( 'gfpdf_post_pdf_save', $entry['form_id'], $entry['id'], $settings, $pdf->get_path() . $pdf->get_filename() );
					}
				}
			}
		}
	}

	/**
	 * Check if the current PDF to be processed already exists on disk
	 * @param  Object $pdf     The Helper_PDF Object
	 * @return Boolean
	 * @since  4.0
	 */
	public function does_pdf_exist( $pdf ) {
		$pdf->setPath();
		$pdf->setFilename();

		if ( is_file( $pdf->getPath() . $pdf->getFilename() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Remove the generated PDF from the server to save disk space
	 * @internal  In future we may give the option to cache PDFs to save on processing power
	 * @param  Array $entry The GF Entry Data
	 * @param  Array $form  The Gravity Form
	 * @return void
	 * @since 4.0
	 */
	public function cleanup_pdf( $entry, $form ) {
		global $gfpdf;

		$pdfs = (isset($form['gfpdf_form_settings'])) ? $this->get_active_pdfs( $form['gfpdf_form_settings'], $entry ) : array();

		if ( sizeof( $pdfs ) > 0 ) {
			/* set up classes */
			$controller  = $this->getController();
			$settingsAPI = new Model_Form_Settings();

			/* loop through each PDF config */
			foreach ( $pdfs as $pdf ) {
				$settings = $settingsAPI->get_pdf( $entry['form_id'], $pdf['id'] );

				/* Only generate if the PDF wasn't during the notification process */
				if ( ! is_wp_error( $settings ) ) {
					$pdf = new Helper_PDF( $entry, $settings );

					if ( $this->does_pdf_exist( $pdf ) ) {
						try {
							$gfpdf->misc->rmdir( $pdf->getPath() );
						} catch (Exception $e) {
							/* Log to file */
						}
					}
				}
			}
		}
	}

	/**
	 * Changes mPDF's tmp and fontdata folders
	 * @param  String $path The current path
	 * @return String       The new path
	 */
	public function mpdf_tmp_path( $path ) {
		global $gfpdf;

		return $gfpdf->data->template_tmp_location;
	}

	/**
	 * An mPDF filter that checks if mPDF has the font currently installed, otherwise
	 * will look in the Gravity PDF font folder for an alternative.
	 * @param String $path The current path to the font mPDF is trying to load
	 * @param String $font The current font name trying to be loaded
	 * @since 4.0
	 */
	public function set_current_pdf_font( $path, $font ) {
		global $gfpdf;

		/* If the current font doesn't exist in mPDF core we'll look in our font folder */
		if ( ! is_file( $path ) ) {

			if ( is_file( $gfpdf->data->template_font_location . $font ) ) {
				$path = $gfpdf->data->template_font_location . $font;
			}
		}

		return $path;
	}

	/**
	 * An mPDF filter which will register our custom font data with mPDF
	 * @param Array $fonts The registered fonts
	 * @since 4.0
	 */
	public function register_custom_font_data_with_mPDF( $fonts ) {
		global $gfpdf;

		$custom_fonts = $gfpdf->options->get_custom_fonts();

		foreach ( $custom_fonts as $font ) {

			$fonts[ $font['shortname'] ] = array_filter(array(
				'R'          => basename( $font['regular'] ),
				'B'          => basename( $font['bold'] ),
				'I'          => basename( $font['italics'] ),
				'BI'         => basename( $font['bolditalics'] ),
			));
		}

		return $fonts;
	}

	/**
	 * Generates our $data array
	 * @param  Array $entry The Gravity Form Entry
	 * @return Array        The $data array
	 * @since 4.0
	 */
	public function get_form_data( $entry ) {
		global $gfpdf;

		$form = $gfpdf->form->get_form( $entry['form_id'] );

		/* Setup our basic structure */
		$data = array(
			'misc'               => array(),
			'field'              => array(),
			'field_descriptions' => array(),
		);

		/**
		 * Create a product class for use
		 * @var Field_Products
		 */
		$products = new Field_Products( $entry );

		/* Get the form details */
		$form_meta = $this->get_form_data_meta( $form, $entry );

		/* Get the survey, quiz and poll data if applicable */
		$quiz   = $this->get_quiz_results( $form, $entry );
		$survey = $this->get_survey_results( $form, $entry );
		$poll   = $this->get_poll_results( $form, $entry );

		/* Merge in the meta data and survey, quiz and poll data */
		$data = array_replace_recursive( $data, $form_meta, $quiz, $survey, $poll );

		/**
		 * Loop through the form data, call the correct field object and
		 * save the data to our $data array
		 */
		$has_product_fields = false;

		foreach ( $form['fields'] as $field ) {

			/* Skip over product fields as they will be grouped at the end */
			if ( GFCommon::is_product_field( $field->type ) ) {
				$has_product_fields = true;
			}

			/* Skip over captcha, password and page fields */
			$fields_to_skip = apply_filters( 'gfpdf_form_data_skip_fields', array( 'captcha', 'password', 'page' ) );

			if ( in_array( $field->type, $fields_to_skip ) ) {
				continue;
			}

			/* Include any field descriptions */
			$data['field_descriptions'][ $field->id ] = ( ! empty($field->description)) ? $field->description : '';

			/* Get our field object */
			$class = $this->get_field_class( $field, $form, $entry, $products );

			/* Merge in the field object form_data() results */
			$data = array_replace_recursive( $data, $class->form_data() );
		}

		/* Load our product array if products exist */
		if ( $has_product_fields ) {
			$data = array_replace_recursive( $data, $products->form_data() );
		}

		/* Re-order the array keys to make it more readable */
		$order = array( 'misc', 'field', 'list', 'signature_details_id', 'products', 'products_totals', 'poll', 'survey', 'quiz', 'pages', 'html_id', 'section_break', 'field_descriptions', 'signature', 'signature_details', 'html' );

		foreach ( $order as $key ) {

			/* If item exists pop it onto the end of the array */
			if ( isset($data[ $key ] ) ) {
				$item = $data[ $key ];
				unset( $data[ $key ] );
				$data[ $key ] = $item;
			}
		}

		return $data;
	}

	/**
	 * Return our general $data information
	 * @param  Array $form The Gravity Form
	 * @param  Array $entry The Gravity Form Entry
	 * @return Array        The $data array
	 * @since 4.0
	 */
	public function get_form_data_meta( $form, $entry ) {
			$data = array();

			/* Add form_id and entry_id for convinience */
			$data['form_id']  = $entry['form_id'];
			$data['entry_id'] = $entry['id'];

			/* Set title and dates (both US and international) */
			$data['form_title']       = (isset($form['title'])) ? $form['title'] : '';
		;
			$data['form_description'] = (isset($form['description'])) ? $form['description'] : '';
			$data['date_created']     = GFCommon::format_date( $entry['date_created'], false, 'j/n/Y', false );
			$data['date_created_usa'] = GFCommon::format_date( $entry['date_created'], false, 'n/j/Y', false );

			/* Include page names */
			$data['pages'] = $form['pagination']['pages'];

			/* Add misc fields */
			$data['misc']['date_time']        = GFCommon::format_date( $entry['date_created'], false, 'Y-m-d H:i:s', false );
			$data['misc']['time_24hr']        = GFCommon::format_date( $entry['date_created'], false, 'H:i', false );
			$data['misc']['time_12hr']        = GFCommon::format_date( $entry['date_created'], false, 'g:ia', false );

			$include = array( 'is_starred', 'is_read', 'ip', 'source_url', 'post_id', 'currency', 'payment_status', 'payment_date', 'transaction_id', 'payment_amount', 'is_fulfilled', 'created_by', 'transaction_type', 'user_agent', 'status' );

		foreach ( $include as $item ) {
			$data['misc'][ $item ] = ( isset( $entry[ $item ]) ) ? $entry[ $item ] : '';
		}

			return $data;
	}

	/**
	 * Pull the Survey Results into the $form_data array
	 * @param  Array $form  The Gravity Form
	 * @param  Array $entry The Gravity Form Entry
	 * @return Array        The results
	 * @since  4.0
	 */
	public function get_survey_results( $form, $entry ) {

		$data = array();

		if ( class_exists( 'GFSurvey' ) && $this->check_field_exists( 'survey', $form ) ) {

			/* Get survey fields */
			$fields = GFCommon::get_fields_by_type( $form, array( 'survey' ) );

			/* Include the survey score, if any */
			if ( isset( $lead['gsurvey_score'] ) ) {
				$data['survey']['score'] = $lead['gsurvey_score'];
			}

			$results = $this->get_addon_global_data( $form, array(), $fields );

			/* Loop through the global survey data and convert information correctly */
			foreach ( $fields as $field ) {

				/* Check if we have a multifield likert and replace the row key */
				if ( isset( $field['gsurveyLikertEnableMultipleRows'] ) && $field['gsurveyLikertEnableMultipleRows'] == 1 ) {

					foreach ( $field['gsurveyLikertRows'] as $row ) {

						$results['field_data'][ $field->id ] = $this->replace_key( $results['field_data'][ $field->id ], $row['value'], $row['text'] );

						if ( isset( $field->choices ) && is_array( $field->choices ) ) {
							foreach ( $field->choices as $choice ) {
								$results['field_data'][ $field->id ][ $row['text'] ] = $this->replace_key( $results['field_data'][ $field->id ][ $row['text'] ], $choice['value'], $choice['text'] );
							}
						}
					}
				}

				/* Replace the standard row data */
				if ( isset( $field->choices ) && is_array( $field->choices ) ) {
					foreach ( $field->choices as $choice ) {
						$results['field_data'][ $field->id ] = $this->replace_key( $results['field_data'][ $field->id ], $choice['value'], $choice['text'] );
					}
				}
			}

			$data['survey']['global'] = $results;
		}

		return $data;
	}

	/**
	 * Pull the Quiz Results into the $form_data array
	 * @param  Array $form  The Gravity Form
	 * @param  Array $entry The Gravity Form Entry
	 * @return Array        The results
	 * @since  4.0
	 */
	public function get_quiz_results( $form, $entry ) {

		$data = array();

		if ( class_exists( 'GFQuiz' ) && $this->check_field_exists( 'quiz', $form )  ) {

			/* Get quiz fields */
			$fields = GFCommon::get_fields_by_type( $form, array( 'quiz' ) );

			/* Store the quiz pass configuration */
			$data['quiz']['config']['grading']     = (isset($form['gravityformsquiz']['grading'])) ?        $form['gravityformsquiz']['grading'] :      '';
			$data['quiz']['config']['passPercent'] = (isset($form['gravityformsquiz']['passPercent'])) ?    $form['gravityformsquiz']['passPercent'] :  '';
			$data['quiz']['config']['grades']      = (isset($form['gravityformsquiz']['grades'])) ?         $form['gravityformsquiz']['grades'] :       '';

			/* Store the user's quiz results */
			$data['quiz']['results']['score']      = rgar( $entry, 'gquiz_score' );
			$data['quiz']['results']['percent']    = rgar( $entry, 'gquiz_percent' );
			$data['quiz']['results']['is_pass']    = rgar( $entry, 'gquiz_is_pass' );
			$data['quiz']['results']['grade']      = rgar( $entry, 'gquiz_grade' );

			/* Poll for the global quiz overall results */
			$data['quiz']['global'] = $this->get_quiz_overall_data( $form, $fields );

		}

		return $data;
	}

	/**
	 * Pull the Poll Results into the $form_data array
	 * @param  Array $form  The Gravity Form
	 * @param  Array $entry The Gravity Form Entry
	 * @return Array        The results
	 * @since  4.0
	 */
	public function get_poll_results( $form, $entry ) {

		$data = array();

		if ( class_exists( 'GFPolls' ) && $this->check_field_exists( 'poll', $form ) ) {

			/* Get poll fields and the overall results */
			$fields  = GFCommon::get_fields_by_type( $form, array( 'poll' ) );
			$results = $this->get_addon_global_data( $form, array(), $fields );

			/* Loop through our fields and update the results as needed */
			foreach ( $fields as $field ) {

				/* Add the field name to a new 'misc' array key */
				$results['field_data'][ $field->id ]['misc']['label'] = $field->label;

				/* Loop through the field choices */
				foreach ( $field->choices as $choice ) {
					$results['field_data'][ $field->id ] = $this->replace_key( $results['field_data'][ $field->id ], $choice['value'], $choice['text'] );
				}
			}

			$data['poll']['global'] = $results;
		}

		return $data;
	}

	/**
	 * Parse the Quiz Overall Results
	 * @param  Array $form   The Gravity Form
	 * @param  Array $fields The quiz fields
	 * @return Array         The parsed results
	 * @since 4.0
	 */
	public function get_quiz_overall_data( $form, $fields ) {

		if ( ! class_exists( 'GFQuiz' ) ) {
			return array();
		}

		/* GFQuiz is a singleton. Get the instance */
		$quiz   = GFQuiz::get_instance();

		/* Create our callback to add additional data to the array specific to the quiz plugin */
		$options['callbacks']['calculation'] = array(
				$quiz,
				'results_calculation',
		);

		$results = $this->get_addon_global_data( $form, $options, $fields );

		/* Loop through our fields and update our global results */
		foreach ( $fields as $field ) {

			/* Replace ['totals'] key with ['misc'] key */
			$results['field_data'][ $field->id ] = $this->replace_key( $results['field_data'][ $field->id ], 'totals', 'misc' );

			/* Add the field name to the ['misc'] key */
			$results['field_data'][ $field->id ]['misc']['label'] = $field->label;

			/* Loop through the field choices */
			if ( is_array( $field->choices ) ) {
				foreach ( $field->choices as $choice ) {
					$results['field_data'][ $field->id ] = $this->replace_key( $results['field_data'][ $field->id ], $choice['value'], $choice['text'] );

					/* Check if this is the correct field */
					if ( isset($choice['gquizIsCorrect']) && $choice['gquizIsCorrect'] == 1 ) {
						$results['field_data'][ $field->id ]['misc']['correct_option_name'][] = $choice['text'];
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Pull Gravity Forms global results Data
	 * @param  Array $form    The Gravity Form array
	 * @param  Array $options The global query options
	 * @param  Array $fields  The field array to use in our query
	 * @return Array          The results
	 * @since 4.0
	 */
	private function get_addon_global_data( $form, $options, $fields ) {
			/* If the results class isn't loaded, load it */
		if ( ! class_exists( 'GFResults' ) ) {
			require_once( GFCommon::get_base_path() . '/includes/addon/class-gf-results.php');
		}

			$form_id = $form['id'];

			/* Add form filter to keep in line with GF standard */
			$form = apply_filters( "gform_form_pre_results_$form_id", apply_filters( 'gform_form_pre_results', $form ) );

			/* Initiate the results class */
			$gf_results = new GFResults( '', $options );

			/* Ensure that only active leads are queried */
			$search = array(
				'field_filters' => array( 'mode' => '' ),
				'status'        => 'active',
			);

			/* Get the results */
			$data = $gf_results->get_results_data( $form, $fields, $search );

			/* Unset some array keys we don't need */
			unset($data['status']);
			unset($data['timestamp']);

			return $data;
	}

	/**
	 * Sniff the form fields and determine if there are any of the $type available
	 * @param  String $type the field type we are looking for
	 * @param  Array  $form the form array
	 * @return Boolean       Whether there is a match or not
	 * @since 4.0
	 */
	public function check_field_exists( $type, $form ) {
		foreach ( $form['fields'] as $field ) {
			if ( $field['type'] == $type ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove existing array item and replace it with a new one
	 * @param  Array  $array The array to be modified
	 * @param  String $key   The key to remove
	 * @param  String $text  The new array key
	 * @return Array        The modified array
	 * @since 4.0
	 */
	public function replace_key( $array, $key, $text ) {
		if ( $key !== $text && isset( $array[ $key ] ) ) {

			/* Replace the array key with the actual field name */
			$array[ $text ] = $array[ $key ];
			unset( $array[ $key ] );
		}
		return $array;
	}

	/**
	 * Pass in a Gravity Form Field Object and get back a Gravity PDF Field Object
	 * @param  Object $field Gravity Form Field Object
	 * @param  Array  $form  The Gravity Form Array
	 * @param  Object $entry The Gravity Form Entry
	 * @param  Object $products A Field_Products Object
	 * @return Object        Gravity PDF Field Object
	 * @since 4.0
	 */
	public function get_field_class( $field, $form, $entry, Field_Products $products ) {
		global $gfpdf;

		$class_name = $gfpdf->misc->get_field_class( $field->type );

		try {
			/* if we have a valid class name... */
			if ( class_exists( $class_name ) ) {

				/**
				 * Developer Note
				 *
				 * We've purposefully not added any filters to the Field_* child classes directly.
				 * Instead, if you want to change how one of the fields are displayed or output (without effecting Gravity Forms itself) you should tap
				 * into one of the filters below and override or extend the entire class.
				 *
				 * Your class MUST extend the \GFPDF\Helper\Helper_Abstract_Fields abstract class - either directly or by extending an existing \GFPDF\Helper\Fields class.
				 * eg. class Fields_New_Text extends \GFPDF\Helper\Helper_Abstract_Fields or Fields_New_Text extends \GFPDF\Helper\Fields\Field_Text
				 *
				 * To make your life more simple you should either use the same namespace as the field classes (\GFPDF\Helper\Fields) or import the class directly (use \GFPDF\Helper\Fields\Field_Text)
				 * We've tried to make the fields as modular as possible. If you have any feedback about this approach please submit a ticket on GitHub (https://github.com/blueliquiddesigns/gravity-forms-pdf-extended/issues)
				 */
				if ( GFCommon::is_product_field( $field->type ) ) {
					/* Product fields are handled through a single function */
					$class = apply_filters( 'gfpdf_field_product_class', new Field_Product( $field, $entry, $products ), $field, $entry, $form );
				} else {
					/* Load the selected class */
					$class = apply_filters( 'gfpdf_field_'. $field->type . '_class', new $class_name($field, $entry), $field, $entry, $form );
				}
			}

			if ( empty($class) || ! ($class instanceof Helper_Abstract_Fields) ) {
				throw new Exception( 'Class not found' );
			}
		} catch (Exception $e) {
			/* Exception thrown. Load generic field loader */
			$class = apply_filters( 'gfpdf_field_default_class', new Field_Default( $field, $entry ), $field, $entry, $form );
		}

		return $class;
	}
}
