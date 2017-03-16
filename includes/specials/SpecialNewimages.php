<?php
/**
 * Implements Special:Newimages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

class SpecialNewFiles extends IncludableSpecialPage {
	/** @var FormOptions */
	protected $opts;

	/** @var string[] */
	protected $mediaTypes;

	public function __construct() {
		parent::__construct( 'Newimages' );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$mimeAnalyzer = MediaWiki\MediaWikiServices::getInstance()->getMimeAnalyzer();
		$this->mediaTypes = $mimeAnalyzer->getMediaTypes();

		$out = $this->getOutput();
		$out->addModules( 'mediawiki.special.newFiles' );
		$this->addHelpLink( 'Help:New images' );

		$opts = new FormOptions();

		$opts->add( 'like', '' );
		$opts->add( 'user', '' );
		$opts->add( 'showbots', false );
		$opts->add( 'hidepatrolled', false );
		$opts->add( 'mediatype', $this->mediaTypes );
		$opts->add( 'limit', 50 );
		$opts->add( 'offset', '' );
		$opts->add( 'start', '' );
		$opts->add( 'end', '' );

		$opts->fetchValuesFromRequest( $this->getRequest() );

		if ( $par !== null ) {
			$opts->setValue( is_numeric( $par ) ? 'limit' : 'like', $par );
		}

		// If start date comes after end date chronologically, swap them.
		// They are swapped in the interface by JS.
		$start = $opts->getValue( 'start' );
		$end = $opts->getValue( 'end' );
		if ( $start !== '' && $end !== '' && $start > $end ) {
			$temp = $end;
			$end = $start;
			$start = $temp;

			$opts->setValue( 'start', $start, true );
			$opts->setValue( 'end', $end, true );
		}

		// if all media types have been selected, wipe out the array to prevent
		// the pointless IN(...) query condition (which would have no effect
		// because every possible type has been selected)
		$missingMediaTypes = array_diff( $this->mediaTypes, $opts->getValue( 'mediatype' ) );
		if ( empty( $missingMediaTypes ) ) {
			$opts->setValue( 'mediatype', [] );
		}

		$opts->validateIntBounds( 'limit', 0, 500 );

		$this->opts = $opts;

		if ( !$this->including() ) {
			$this->setTopText();
			$this->buildForm();
		}

		$pager = new NewFilesPager( $this->getContext(), $opts );

		$out->addHTML( $pager->getBody() );
		if ( !$this->including() ) {
			$out->addHTML( $pager->getNavigationBar() );
		}
	}

	protected function buildForm() {
		$mediaTypesText = array_map( function ( $type ) {
			// mediastatistics-header-unknown, mediastatistics-header-bitmap,
			// mediastatistics-header-drawing, mediastatistics-header-audio,
			// mediastatistics-header-video, mediastatistics-header-multimedia,
			// mediastatistics-header-office, mediastatistics-header-text,
			// mediastatistics-header-executable, mediastatistics-header-archive,
			return $this->msg( 'mediastatistics-header-' . strtolower( $type ) )->text();
		}, $this->mediaTypes );
		$mediaTypesOptions = array_combine( $mediaTypesText, $this->mediaTypes );
		ksort( $mediaTypesOptions );

		$formDescriptor = [
			'like' => [
				'type' => 'text',
				'label-message' => 'newimages-label',
				'name' => 'like',
			],

			'user' => [
				'type' => 'text',
				'label-message' => 'newimages-user',
				'name' => 'user',
			],

			'showbots' => [
				'type' => 'check',
				'label-message' => 'newimages-showbots',
				'name' => 'showbots',
			],

			'hidepatrolled' => [
				'type' => 'check',
				'label-message' => 'newimages-hidepatrolled',
				'name' => 'hidepatrolled',
			],

			'mediatype' => [
				'type' => 'multiselect',
				'dropdown' => true,
				'flatlist' => true,
				'name' => 'mediatype',
				'label-message' => 'newimages-mediatype',
				'options' => $mediaTypesOptions,
				'default' => $this->mediaTypes,
			],

			'limit' => [
				'type' => 'hidden',
				'default' => $this->opts->getValue( 'limit' ),
				'name' => 'limit',
			],

			'offset' => [
				'type' => 'hidden',
				'default' => $this->opts->getValue( 'offset' ),
				'name' => 'offset',
			],

			'start' => [
				'type' => 'date',
				'label-message' => 'date-range-from',
				'name' => 'start',
			],

			'end' => [
				'type' => 'date',
				'label-message' => 'date-range-to',
				'name' => 'end',
			],
		];

		if ( $this->getConfig()->get( 'MiserMode' ) ) {
			unset( $formDescriptor['like'] );
		}

		if ( !$this->getUser()->useFilePatrol() ) {
			unset( $formDescriptor['hidepatrolled'] );
		}

		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			// For the 'multiselect' field values to be preserved on submit
			->setFormIdentifier( 'specialnewimages' )
			->setWrapperLegendMsg( 'newimages-legend' )
			->setSubmitTextMsg( 'ilsubmit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );
	}

	protected function getGroupName() {
		return 'changes';
	}

	/**
	 * Send the text to be displayed above the options
	 */
	function setTopText() {
		global $wgContLang;

		$message = $this->msg( 'newimagestext' )->inContentLanguage();
		if ( !$message->isDisabled() ) {
			$this->getOutput()->addWikiText(
				Html::rawElement( 'p',
					[ 'lang' => $wgContLang->getHtmlCode(), 'dir' => $wgContLang->getDir() ],
					"\n" . $message->plain() . "\n"
				),
				/* $lineStart */ false,
				/* $interface */ false
			);
		}
	}
}
