<?php

namespace WikibaseQuality\ConstraintReport\Specials;

use Config;
use DataValues\DataValue;
use Html;
use HTMLForm;
use IBufferingStatsdDataFactory;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use UnexpectedValueException;
use ValueFormatters\FormatterOptions;
use ValueFormatters\ValueFormatter;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Message\MultilingualTextViolationMessageRenderer;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Message\ViolationMessageRenderer;
use WikibaseQuality\ConstraintReport\ConstraintParameterRenderer;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\EntityId\EntityIdFormatter;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\OutputFormatValueFormatterFactory;
use Wikibase\Lib\SnakFormatter;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookupFactory;
use Wikibase\Repo\EntityIdHtmlLinkFormatterFactory;
use Wikibase\Repo\EntityIdLabelFormatterFactory;
use Wikibase\Repo\WikibaseRepo;
use WikibaseQuality\ConstraintReport\ConstraintCheck\DelegatingConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use WikibaseQuality\ConstraintReport\ConstraintReportFactory;
use WikibaseQuality\Html\HtmlTableBuilder;
use WikibaseQuality\Html\HtmlTableCellBuilder;
use WikibaseQuality\Html\HtmlTableHeaderBuilder;

/**
 * Special page that displays all constraints that are defined on an Entity with additional information
 * (whether it complied or was a violation, which parameters the constraint has etc.).
 *
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class SpecialConstraintReport extends SpecialPage {

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var EntityTitleLookup
	 */
	private $entityTitleLookup;

	/**
	 * @var ValueFormatter
	 */
	private $dataValueFormatter;

	/**
	 * @var EntityIdFormatter
	 */
	private $entityIdLabelFormatter;

	/**
	 * @var EntityIdFormatter
	 */
	private $entityIdLinkFormatter;

	/**
	 * @var DelegatingConstraintChecker
	 */
	private $constraintChecker;

	/**
	 * @var ConstraintParameterRenderer
	 */
	private $constraintParameterRenderer;

	/**
	 * @var ViolationMessageRenderer
	 */
	private $violationMessageRenderer;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var IBufferingStatsdDataFactory
	 */
	private $dataFactory;

	public static function newFromGlobalState() {
		$constraintReportFactory = ConstraintReportFactory::getDefaultInstance();
		$wikibaseRepo = WikibaseRepo::getDefaultInstance();
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$dataFactory = MediaWikiServices::getInstance()->getStatsdDataFactory();

		return new self(
			$wikibaseRepo->getEntityLookup(),
			$wikibaseRepo->getEntityTitleLookup(),
			new EntityIdLabelFormatterFactory(),
			$wikibaseRepo->getEntityIdHtmlLinkFormatterFactory(),
			$wikibaseRepo->getLanguageFallbackLabelDescriptionLookupFactory(),
			$wikibaseRepo->getEntityIdParser(),
			$wikibaseRepo->getValueFormatterFactory(),
			$constraintReportFactory->getConstraintChecker(),
			$config,
			$dataFactory
		);
	}

	public function __construct(
		EntityLookup $entityLookup,
		EntityTitleLookup $entityTitleLookup,
		EntityIdLabelFormatterFactory $entityIdLabelFormatterFactory,
		EntityIdHtmlLinkFormatterFactory $entityIdHtmlLinkFormatterFactory,
		LanguageFallbackLabelDescriptionLookupFactory $fallbackLabelDescLookupFactory,
		EntityIdParser $entityIdParser,
		OutputFormatValueFormatterFactory $valueFormatterFactory,
		DelegatingConstraintChecker $constraintChecker,
		Config $config,
		IBufferingStatsdDataFactory $dataFactory
	) {
		parent::__construct( 'ConstraintReport' );

		$this->entityLookup = $entityLookup;
		$this->entityTitleLookup = $entityTitleLookup;
		$this->entityIdParser = $entityIdParser;

		$language = $this->getLanguage();

		$formatterOptions = new FormatterOptions();
		$formatterOptions->setOption( SnakFormatter::OPT_LANG, $language->getCode() );
		$this->dataValueFormatter = $valueFormatterFactory->getValueFormatter(
			SnakFormatter::FORMAT_HTML,
			$formatterOptions
		);

		$labelLookup = $fallbackLabelDescLookupFactory->newLabelDescriptionLookup( $language );

		$this->entityIdLabelFormatter = $entityIdLabelFormatterFactory->getEntityIdFormatter(
			$labelLookup
		);

		$this->entityIdLinkFormatter = $entityIdHtmlLinkFormatterFactory->getEntityIdFormatter(
			$labelLookup
		);

		$this->constraintChecker = $constraintChecker;

		$this->constraintParameterRenderer = new ConstraintParameterRenderer(
			$this->entityIdLabelFormatter,
			$this->dataValueFormatter,
			$config
		);
		$this->violationMessageRenderer = new MultilingualTextViolationMessageRenderer(
			$this->entityIdLabelFormatter,
			$this->dataValueFormatter,
			$config
		);

		$this->config = $config;
		$this->dataFactory = $dataFactory;
	}

	/**
	 * Returns array of modules that should be added
	 *
	 * @return array
	 */
	private function getModules() {
		return [ 'SpecialConstraintReportPage' ];
	}

	/**
	 * @see SpecialPage::getGroupName
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikibasequality';
	}

	/**
	 * @see SpecialPage::getDescription
	 *
	 * @return string
	 */
	public function getDescription() {
		return $this->msg( 'wbqc-constraintreport' )->escaped();
	}

	/**
	 * @see SpecialPage::execute
	 *
	 * @param string|null $subPage
	 *
	 * @throws InvalidArgumentException
	 * @throws EntityIdParsingException
	 * @throws UnexpectedValueException
	 */
	public function execute( $subPage ) {
		$out = $this->getOutput();

		$postRequest = $this->getContext()->getRequest()->getVal( 'entityid' );
		if ( $postRequest ) {
			$out->redirect( $this->getPageTitle( strtoupper( $postRequest ) )->getLocalURL() );
			return;
		}

		$out->addModules( $this->getModules() );

		$this->setHeaders();

		$out->addHTML( $this->getExplanationText() );
		$this->buildEntityIdForm();

		if ( !$subPage ) {
			return;
		}

		if ( !is_string( $subPage ) ) {
			throw new InvalidArgumentException( '$subPage must be string.' );
		}

		try {
			$entityId = $this->entityIdParser->parse( $subPage );
		} catch ( EntityIdParsingException $e ) {
			$out->addHTML(
				$this->buildNotice( 'wbqc-constraintreport-invalid-entity-id', true )
			);
			return;
		}

		if ( !$this->entityLookup->hasEntity( $entityId ) ) {
			$out->addHTML(
				$this->buildNotice( 'wbqc-constraintreport-not-existent-entity', true )
			);
			return;
		}

		$this->dataFactory->increment(
			'wikibase.quality.constraints.specials.specialConstraintReport.executeCheck'
		);
		$results = $this->constraintChecker->checkAgainstConstraintsOnEntityId( $entityId );

		if ( count( $results ) > 0 ) {
			$out->addHTML(
				$this->buildResultHeader( $entityId )
				. $this->buildSummary( $results )
				. $this->buildResultTable( $entityId, $results )
			);
		} else {
			$out->addHTML(
				$this->buildResultHeader( $entityId )
				. $this->buildNotice( 'wbqc-constraintreport-empty-result' )
			);
		}
	}

	/**
	 * Builds html form for entity id input
	 */
	private function buildEntityIdForm() {
		$formDescriptor = [
			'entityid' => [
				'class' => 'HTMLTextField',
				'section' => 'section',
				'name' => 'entityid',
				'label-message' => 'wbqc-constraintreport-form-entityid-label',
				'cssclass' => 'wbqc-constraintreport-form-entity-id',
				'placeholder' => $this->msg( 'wbqc-constraintreport-form-entityid-placeholder' )->escaped()
			]
		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'wbqc-constraintreport-form' );
		$htmlForm->setSubmitText( $this->msg( 'wbqc-constraintreport-form-submit-label' )->escaped() );
		$htmlForm->setSubmitCallback( function() {
			return false;
		} );
		$htmlForm->setMethod( 'post' );
		$htmlForm->show();
	}

	/**
	 * Builds notice with given message. Optionally notice can be handles as error by settings $error to true
	 *
	 * @param string $messageKey
	 * @param bool $error
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string HTML
	 */
	private function buildNotice( $messageKey, $error = false ) {
		if ( !is_string( $messageKey ) ) {
			throw new InvalidArgumentException( '$message must be string.' );
		}
		if ( !is_bool( $error ) ) {
			throw new InvalidArgumentException( '$error must be bool.' );
		}

		$cssClasses = 'wbqc-constraintreport-notice';
		if ( $error ) {
			$cssClasses .= ' wbqc-constraintreport-notice-error';
		}

		return Html::rawElement(
				'p',
				[
					'class' => $cssClasses
				],
				$this->msg( $messageKey )->escaped()
			);
	}

	/**
	 * @return string HTML
	 */
	private function getExplanationText() {
		return Html::rawElement(
			'div',
			[ 'class' => 'wbqc-explanation' ],
			Html::rawElement(
				'p',
				[],
				$this->msg( 'wbqc-constraintreport-explanation-part-one' )->escaped()
			)
			. Html::rawElement(
				'p',
				[],
				$this->msg( 'wbqc-constraintreport-explanation-part-two' )->escaped()
			)
		);
	}

	/**
	 * @param EntityId $entityId
	 * @param CheckResult[] $results
	 *
	 * @return string HTML
	 */
	private function buildResultTable( EntityId $entityId, array $results ) {
		// Set table headers
		$table = new HtmlTableBuilder(
			[
				new HtmlTableHeaderBuilder(
					$this->msg( 'wbqc-constraintreport-result-table-header-status' )->escaped(),
					true
				),
				new HtmlTableHeaderBuilder(
					$this->msg( 'wbqc-constraintreport-result-table-header-claim' )->escaped(),
					true
				),
				new HtmlTableHeaderBuilder(
					$this->msg( 'wbqc-constraintreport-result-table-header-constraint' )->escaped(),
					true
				)
			]
		);

		foreach ( $results as $result ) {
			$table = $this->appendToResultTable( $table, $entityId, $result );
		}

		return $table->toHtml();
	}

	private function appendToResultTable( HtmlTableBuilder $table, EntityId $entityId, CheckResult $result ) {
		// Status column
		$statusColumn = $this->buildTooltipElement(
			$this->formatStatus( $result->getStatus() ),
			$result->getMessage() !== null ?
				$this->violationMessageRenderer->render( $result->getMessage() ) :
				null,
			'[?]'
		);

		// Claim column
		$property = $this->entityIdLabelFormatter->formatEntityId( $result->getContext()->getSnak()->getPropertyId() );
		if ( $result->getSnakType() === 'value' ) {
			$value = $this->constraintParameterRenderer->formatValue( $result->getDataValue() );
		} else {
			$value = htmlspecialchars( $result->getSnakType() );
		}

		$claimColumn = $this->getClaimLink(
			$entityId,
			$result->getContext()->getSnak()->getPropertyId(),
			$property . ': ' . $value
		);

		// Constraint column
		$constraintTypeItemId = $result->getConstraint()->getConstraintTypeItemId();
		try {
			$constraintTypeLabel = $this->entityIdLabelFormatter->formatEntityId( new ItemId( $constraintTypeItemId ) );
		} catch ( InvalidArgumentException $e ) {
			$constraintTypeLabel = htmlspecialchars( $constraintTypeItemId );
		}
		$constraintLink = $this->getClaimLink(
			$result->getContext()->getSnak()->getPropertyId(),
			new PropertyId( $this->config->get( 'WBQualityConstraintsPropertyConstraintId' ) ),
			$constraintTypeLabel
		);
		$constraintColumn = $this->buildExpandableElement(
			$constraintLink,
			$this->constraintParameterRenderer->formatParameters( $result->getParameters() ),
			'[...]'
		);

		// Append cells
		$table->appendRow(
			[
				new HtmlTableCellBuilder(
					$statusColumn,
					[],
					true
				),
				new HtmlTableCellBuilder(
					$claimColumn,
					[],
					true
				),
				new HtmlTableCellBuilder(
					$constraintColumn,
					[],
					true
				)
			]
		);

		return $table;
	}

	/**
	 * Returns html text of the result header
	 *
	 * @param EntityId $entityId
	 *
	 * @return string HTML
	 */
	protected function buildResultHeader( EntityId $entityId ) {
		$entityLink = sprintf( '%s (%s)',
							   $this->entityIdLinkFormatter->formatEntityId( $entityId ),
							   htmlspecialchars( $entityId->getSerialization() ) );

		return Html::rawElement(
			'h3',
			[],
			sprintf( '%s %s', $this->msg( 'wbqc-constraintreport-result-headline' )->escaped(), $entityLink )
		);
	}

	/**
	 * Builds summary from given results
	 *
	 * @param CheckResult[] $results
	 *
	 * @return string HTML
	 */
	protected function buildSummary( array $results ) {
		$statuses = [];
		foreach ( $results as $result ) {
			$status = strtolower( $result->getStatus() );
			$statuses[$status] = isset( $statuses[$status] ) ? $statuses[$status] + 1 : 1;
		}

		$statusElements = [];
		foreach ( $statuses as $status => $count ) {
			if ( $count > 0 ) {
				$statusElements[] =
					$this->formatStatus( $status )
					. ': '
					. $count;
			}
		}

		return Html::rawElement( 'p', [], implode( ', ', $statusElements ) );
	}

	/**
	 * Builds a html div element with given content and a tooltip with given tooltip content
	 * If $tooltipContent is null, no tooltip will be created
	 *
	 * @param string $content (sanitized HTML)
	 * @param string $tooltipContent (sanitized HTML)
	 * @param string $indicator
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string HTML
	 */
	protected function buildTooltipElement( $content, $tooltipContent, $indicator ) {
		if ( !is_string( $content ) ) {
			throw new InvalidArgumentException( '$content has to be string.' );
		}
		if ( $tooltipContent && ( !is_string( $tooltipContent ) ) ) {
			throw new InvalidArgumentException( '$tooltipContent, if provided, has to be string.' );
		}

		if ( empty( $tooltipContent ) ) {
			return $content;
		}

		$tooltip = Html::rawElement(
			'div',
			[
				'class' => 'wbqc-tooltip'
			],
			$tooltipContent
		);

		$tooltipIndicator = Html::rawElement(
			'span',
			[
				'class' => 'wbqc-indicator'
			],
			htmlspecialchars( $indicator ) . $tooltip
		);

		return Html::rawElement(
			'span',
			[],
			sprintf( '%s %s', $content, $tooltipIndicator )
		);
	}

	/**
	 * Builds a html div element with given content and a tooltip with given tooltip content
	 * If $tooltipContent is null, no tooltip will be created
	 *
	 * @param string $content
	 * @param string $expandableContent
	 * @param string $indicator
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string HTML
	 */
	protected function buildExpandableElement( $content, $expandableContent, $indicator ) {
		if ( !is_string( $content ) ) {
			throw new InvalidArgumentException( '$content has to be string.' );
		}
		if ( $expandableContent && ( !is_string( $expandableContent ) ) ) {
			throw new InvalidArgumentException( '$tooltipContent, if provided, has to be string.' );
		}

		if ( empty( $expandableContent ) ) {
			return $content;
		}

		$tooltipIndicator = Html::element(
			'span',
			[
				'class' => 'wbqc-expandable-content-indicator wbqc-indicator'
			],
			$indicator
		);

		$expandableContent = Html::element(
			'div',
			[
				'class' => 'wbqc-expandable-content'
			],
			$expandableContent
		);

		return sprintf( '%s %s %s', $content, $tooltipIndicator, $expandableContent );
	}

	/**
	 * Formats given status to html
	 *
	 * @param string $status
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string HTML
	 */
	private function formatStatus( $status ) {
		$messageName = "wbqc-constraintreport-status-" . strtolower( $status );

		$formattedStatus =
			Html::element(
				'span',
				[
					'class' => 'wbqc-status wbqc-status-' . $status
				],
				$this->msg( $messageName )->text()
			);

		return $formattedStatus;
	}

	/**
	 * Parses data values to human-readable string
	 *
	 * @param DataValue|array $dataValues
	 * @param string $separator
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string HTML
	 */
	protected function formatDataValues( $dataValues, $separator = ', ' ) {
		if ( $dataValues instanceof DataValue ) {
			$dataValues = [ $dataValues ];
		} elseif ( !is_array( $dataValues ) ) {
			throw new InvalidArgumentException( '$dataValues has to be instance of DataValue or an array of DataValues.' );
		}

		$formattedDataValues = [];
		foreach ( $dataValues as $dataValue ) {
			if ( !( $dataValue instanceof DataValue ) ) {
				throw new InvalidArgumentException( '$dataValues has to be instance of DataValue or an array of DataValues.' );
			}
			if ( $dataValue instanceof EntityIdValue ) {
				$formattedDataValues[ ] = $this->entityIdLabelFormatter->formatEntityId( $dataValue->getEntityId() );
			} else {
				$formattedDataValues[ ] = $this->dataValueFormatter->format( $dataValue );
			}
		}

		return implode( $separator, $formattedDataValues );
	}

	/**
	 * Returns html link to given entity with anchor to specified property.
	 *
	 * @param EntityId $entityId
	 * @param PropertyId $propertyId
	 * @param string $text HTML
	 *
	 * @return string HTML
	 */
	private function getClaimLink( EntityId $entityId, PropertyId $propertyId, $text ) {
		return Html::rawElement(
			'a',
			[
				'href' => $this->getClaimUrl( $entityId, $propertyId ),
				'target' => '_blank'
			],
			$text
		);
	}

	/**
	 * Returns url of given entity with anchor to specified property.
	 *
	 * @param EntityId $entityId
	 * @param PropertyId $propertyId
	 *
	 * @return string
	 */
	private function getClaimUrl( EntityId $entityId, PropertyId $propertyId ) {
		$title = $this->entityTitleLookup->getTitleForId( $entityId );
		$entityUrl = sprintf( '%s#%s', $title->getLocalURL(), $propertyId->getSerialization() );

		return $entityUrl;
	}

}
