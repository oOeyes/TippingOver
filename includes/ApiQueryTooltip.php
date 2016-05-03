<?php

/**
 * This is a simple top-level API module that provides some shortcut API queries that allow certain backend calls to
 * be performed by TippingOver in a single request, rather than the two that would be needed in some cases by using
 * the standard available queries.
 * 
 * @author Eyes <eyes@aeongarden.com>
 * @copyright Copyright � 2015 Eyes
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class APIQueryTooltip extends APIBase {
  
  /**
   * Holds the parser options
   * @var ParserOptions
   */
  private $mParserOptions = null;
  
  /**
   * Initializes a ParserOptions instance.
   */
  private function initializeParserOptions() {
    if ( $this->mParserOptions === null ) {
      $this->mParserOptions = new ParserOptions;
      $this->mParserOptions->setEditSection( false );
    }
  }
  
  /**
   * Gets the options requested in the options param into an array form, or errors out the request if the options
   * are invalid.
   * @return Array An array with the available option keywords as keys and true or false as values.
   */
  private function getOptions() {
    $optionValues = Array();
    $optionKeywords = Array( "exists", "title", "image", "cat", "text" );
    if ( isset( $this->params['options'] ) ) {
      $optionValues = $this->parseMultiValue( 'options', $this->params['options'], true, $optionKeywords );
    }
    $options = Array();
    $options['exists'] = in_array( "exists", $optionValues );
    $options['title'] = in_array( "title", $optionValues );
    $options['image'] = in_array( "image", $optionValues );
    $options['cat'] = in_array( "cat", $optionValues );
    if ( $options['cat'] && !isset( $this->params['target'] ) ) {
      $this->dieUsage( 'Category filtering cannot be done without the target parameter.', 'no_target_for_cat_filter' );
    }
    $options['text'] = in_array( "text", $optionValues );
    
    return $options;
  }
  
  /**
   * This function gets the tooltip title in string form. If it's available directy from the tooltip param supplied
   * in the request, it returns that. Otherwise, it will use MediaWiki:To-tooltip-page-title to try to transform
   * the title supplied in the target parameter, but only if the options specified actually require knowing the
   * tooltip page title; otherwise, it skips that step and returns null. It also returns null if the target title
   * cannot be resolved.
   * @param $options Array of options, indexed by name and true for requested options.
   * @return string The tooltip page title in string form, or null if not needed or on errors.
   */
  private function getTooltipTitleText( $options ) {
    if ( isset( $this->params['tooltip'] ) ) {
      return $this->params['tooltip'];
    }
    if ( $options['exists'] || $options['image'] || $options['text'] || $options['title'] ) {
      if ( isset( $this->params['target'] ) ) {
        $targetTitle = Title::newFromText( $this->params['target'] );
        if ( $targetTitle !== null ) {
          // For #ask and #show in SMW, the parse can't come through the message cache, so we do this reroute.
          // See https://github.com/SemanticMediaWiki/SemanticMediaWiki/issues/1181
          $tooltipTitleCode = wfMessage( 'to-tooltip-page-name' )->inContentLanguage()
                                                                 ->params( $targetTitle->getPrefixedText() )
                                                                 ->plain();
          return Parser::stripOuterParagraph( $this->parse( $tooltipTitleCode, 
                                                            Title::newFromText( 'MediaWiki:To-tooltip-page-name' ) 
                                                          ) 
                                            );
        } else {
          return null;
        }
      } else {
        return null;
      }
    }
  }
  
  /**
   * Runs the parser to get the fully parsed content for a tooltip to return.
   * @param Title $tooltipTitle The title of the tooltip to get content from.
   * @return string The tooltip content parsed to HTML.
   */
  private function parseTooltip( $tooltipTitle ) {
    return $this->parse( WikiTooltips::getTooltipWikiText( $tooltipTitle ), $tooltipTitle );
  }
  
  /**
   * Parses the supplied wikitext.
   * @global Parser $wgParser The MediaWiki parser.
   * @param string $wikitext The text to parse.
   * @param Title $title The title to supply for the parser.
   * @return string The wikitext parsed to HTML.
   */
  private function parse( $wikitext, $title ) {
    global $wgParser;
    
    $output = $wgParser->parse( $wikitext, $title, $this->mParserOptions );
    return $output->getText();
  }
  
  /**
   * Processes the API requests and adds the appropriate results.
   * @param Array $options The array of options from the getOptions function.
   */
  private function addResults( $options ) {
    global $wgtoCategoryFiltering, $wgtoCategoryFilterMode;
    
    $result = $this->getResult();
    
    if ( $options['cat'] ) {
      if ( $wgtoCategoryFiltering !== TO_DISABLE ) {
        $targetTitle = Title::newFromText( $this->params['target'] );
        if ( $targetTitle !== null ) {
          $category = WikiTooltips::getFilterCategoryTitle()->getText();
          $finder = new CategoryFinder;
          $finder->seed( Array( $targetTitle->getArticleID() ), Array( $category ) );
          if ( count( $finder->run() ) === 1 ) {
            $result->addValue( null, 
                               'passesCategoryFilter', 
                               ($wgtoCategoryFilterMode === TO_ENABLING) ? 'true' : 'false'
                             );
          } else {
            $result->addValue( null, 
                               'passesCategoryFilter', 
                               ($wgtoCategoryFilterMode === TO_DISABLING) ? 'true' : 'false'
                             );
          }
        } else {
          $result->addValue( null, 'passesCategoryFilter', 'false' );
        }
      } else {
        $result->addValue( null, 'passesCategoryFilter', 'true' );
      }
    }
    
    $tooltipTitleText = $this->getTooltipTitleText( $options );
    if ( $tooltipTitleText !== null && trim( $tooltipTitleText ) !== '' ) {
      $tooltipTitle = Title::newFromText( $tooltipTitleText );
      if ( $tooltipTitle !== null ) {
        if ( $options['title'] ) {
          $result->addValue( null, 'tooltipTitle', $tooltipTitle->getPrefixedText() );
        }
        if ( $options['image'] ) {
          $result->addValue( null, 'isImage', ( $tooltipTitle->getNamespace() === NS_FILE ) ? 'true' : 'false');
        }
        if ( !$options['exists'] || $tooltipTitle->exists() ) {
          if ( $options['exists'] ) {
            $result->addValue( null, 'exists', 'true' );
          }
          if ( $options['text'] ) {
            $result->addValue( 'text', '*', $this->parseTooltip( $tooltipTitle ) );
          }
        } else if ( $options['exists'] ) {
          $result->addValue( null, 'exists', 'false' );
        }
      }
    } else if ( $tooltipTitleText !== null ) {
      $result->addValue( null, 'tooltipTitle', '' );
    }
  }
  
  /**
   * Executes the API request for given tooltip content.
   */
  public function execute( ) {
    $this->params = $this->extractRequestParams();
    $this->requireAtLeastOneParameter( $this->params, 'target', 'tooltip' );
    
    $this->initializeParserOptions();
    
    $this->addResults( $this->getOptions() );
    
    $this->getMain()->setCacheMode( 'public' );
  }
  
  /**
   * Returns the names and metadata of the allowed parameters.
   * @return Array Returns the names and metadata of the allowed parameters.
   */
  public function getAllowedParams( ) {
    return Array( 'target' => Array( ApiBase::PARAM_TYPE => 'string' ),
                  'tooltip' => Array( ApiBase::PARAM_TYPE => 'string' ),
                  'options' => Array( ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ),
                );
  }
  
  /**
   * Returns a description of the allowed parameters.
   * @return Array A description of the allowed parameters.
   */
  public function getParamDescription( ) {
    return Array( 'target' => Array( 'The title of the page the link links to. ',
                                     'Optional unless the "categoryfilter" option is requested or the tooltip page ',
                                     'name is not supplied.',
                                   ),
                  'tooltip' => Array( 'The title of the page to get tooltip content from. ',
                                      'Optional if the target page name is supplied and completely ignored if the ',
                                      '"exists" and "text" options are not requsted.'
                                    ),
                  'options' => Array( 'An pipe-separated list containing one or more of the following: ',
                                      '"text": returns the parsed tooltip output to show in "text" unless ',
                                      'any verification requested in other options fails; ',
                                      '"title": returns the title of the tooltip page in "tooltipTitle"; ',
                                      '"image": indicates whether the tooltip page is from the File namespace in ', 
                                      '"isImage" ',
                                      '"exists": verify the page exists first and return this in "exists"; ',
                                      '"cat": verify the page passes category filter options and return this in ',
                                      '"passesCategoryFilter".',
                                    ),
                );
  }
  
  /**
   * Returns a description of the module.
   * @return Array A description of this module.
   */
  public function getDescription( ) {
    return Array( 'Given a target page and/or tooltip page for a link, returns one or more of the following items: ',
                  'the title of the tooltip page, whether or not it is an image from the File: namespace, whether the ',
                  'tooltip page exists, whether it passes a category filter check, and/or the rendered content of the ',
                  'tooltip page itself.'
                );
  }
  
  /**
   * Returns a version string. Not consistent with other API modules since I'm not yet using SVN.
   * @return string A version string.
   */
  public function getVersion( ) {
    return __CLASS__ . ': TippingOver 0.61';
  }
}