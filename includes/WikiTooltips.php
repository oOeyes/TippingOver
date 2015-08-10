<?php

/**
 * This singleton class handles most basic tooltip functions that occur during a page load, along with a few general
 * tooltip functions.
 *
 * @author Eyes <eyes@aeongarden.com>
 * @copyright Copyright � 2015 Eyes
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class WikiTooltips {
  /**
   * The element class ID prefix for a tooltip div.
   */
  const TOOLTIP_CLASS_ID = 'to-tooltip-';
  
  /**
   * The element class ID prefix for a preloading div.
   */
  const PRELOAD_CLASS_ID = 'to-preload-';
  
  /**
   * Holds the singleton instance.
   * @var WikiTooltips
   */
  private static $mInstance = null;
  
  /**
   * Holds a parser instance
   * @var Parser
   */
  private $mParser = null;
  
  /**
   * Holds the parser options
   * @var ParserOptions
   */
  private $mParserOptions = null;
  
  /**
   * Creates the singleton instance if it doesn't exist and returns it either way.
   * @return WikiTooltips The singleton instance.
   */
  public static function getInstance() {
    if ( self::$mInstance === null ) {
      self::$mInstance = new self();
    }
    
    return self::$mInstance;
  }
  
  /**
   * A sorted array of category members, used for the TO_PREQUERY category filter mode.
   * @var Array
   */
  private $mCategoryFilterLookup = Array();
  
  /**
   * An array keyed by ids of pages previously checked against the category filter, containing true for those that
   * passed and false for those that didn't. Used for the TO_DO_EARLY category filter mode.
   * @var Array
   */
  private $mCategoryFilterCache = Array();
  
  /**
   * Indicates if we've initialized all variables needed to process links. This is a kludge as some of these need
   * access to the parser. Eventually, a better hook with parser access should be identified to avoid all the
   * redundant checks of this state, but due to time issues, this is the solution for now.
   * @var bool
   */
  private $mIsLinkProcessingInitialized = false;
  
  /**
   * The parsed HTML for the loading tooltip, or null if this is disabled somehow.
   * @var String
   */
  private $mLoadingTooltipHtml;
  
  /**
   * Indicates if the loading tooltip should be preloaded.
   * @var bool
   */
  private $mPreloadLoadingTooltip;
  
  /**
   * The parsed HTML for the missing page tooltip, or null if this is disabled somehow.
   * @var String
   */
  private $mMissingPageTooltipHtml;
  
  /**
   * Indicates if the missing page tooltip should be preloaded.
   * @var bool
   */
  private $mPreloadMissingPageTooltip;
  
  /**
   * The parsed HTML for the empty page name tooltip, or null if this is disabled somehow.
   * @var String
   */
  private $mEmptyPageNameTooltipHtml;
  
  /**
   * Indicates if the empty page name tooltip should be preloaded.
   * @var bool
   */
  private $mPreloadEmptyPageNameTooltip;
  
  /**
   * Indicates if a two-request process should be used instead of one. This happens in certain configurations to avoid
   * showing a loading tooltip when there is a strong possibility that there is no tooltip to show.
   * @var bool
   */
  private $mUseTwoRequestProcess;
  
  /**
   * This is a singleton, so private constructor.
   */
  private function __construct() { }
  
  /**
   * Run in the BeforeInitialize hook, this attachs functions to various hooks for tooltip processing and registers
   * the JavaScript module if the current page is in a namespace where tooltips are enabled.
   * @global Array $wgHooks The global where hooks are registered in MediaWiki.
   * @global Array $wgtoEnableInNamespaces Which namespaces tooltips are enabled in.
   * @param Title $title The title being requested.
   * @param Article $article The article object. Ignored.
   * @param OutputPage $output The output page.
   * @param User $user The user object. Ignored.
   * @param WebRequest $request The request object. Ignored.
   * @param MediaWiki $mediawiki The MediaWiki object. Ignored.
   */
  public function initializeHooksAndModule( &$title, &$article, &$output, &$user, $request, $mediawiki ) {
    global $wgHooks, $wgtoEnableInNamespaces;
    
    if ( array_key_exists( $title->getNamespace(), $wgtoEnableInNamespaces ) && 
         $wgtoEnableInNamespaces[$title->getNamespace()] === true
       ) {
      $wgHooks['MakeGlobalVariablesScript'][] = Array( $this, 'registerParsedConfigVarsForScriptExport' );
      $wgHooks['LinkEnd'][] = Array( $this, 'checkAndAttachTooltip' );
      
      $output->addModules( 'ext.TippingOver.wikiTooltips' );
    }
  }
  
  /**
   * Run in the MakeGlobalVariablesScript hook, this exports values needed by toWikiTooltips.js to perform the
   * client-side tooltip functions.
   * @global int $wgtoPageTitleParse When to do the parsing of the target title into the tooltip title page.
   * @global int $wgtoExistsCheck Whether and when to do a check to see if the tooltip page exists.
   * @global int $wgtoCategoryFiltering Whether category filtering is enabled at all and when and how it happens.
   * @param Array $vars The variables to export.
   * @param OutputPage $out An OutputPage instance. Not used.
   */
  public function registerParsedConfigVarsForScriptExport( &$vars, $out ) {
    global $wgtoPageTitleParse, $wgtoExistsCheck, $wgtoCategoryFiltering;
    
    $vars['wgTippingOver']['doLatePageTitleParse'] = ( $wgtoPageTitleParse === TO_RUN_LATE );
    $vars['wgTippingOver']['doLateExistsCheck'] = ( $wgtoExistsCheck === TO_RUN_LATE );
    $vars['wgTippingOver']['doLateCategoryFiltering'] = ( $wgtoCategoryFiltering === TO_RUN_LATE );
    $vars['wgTippingOver']['loadingTooltip'] = $this->mLoadingTooltipHtml;
    $vars['wgTippingOver']['missingPageTooltip'] = $this->mMissingPageTooltipHtml;
    $vars['wgTippingOver']['emptyPageNameTooltip'] = $this->mEmptyPageNameTooltipHtml;
    $vars['wgTippingOver']['preloadLoadingTooltip'] = $this->mPreloadLoadingTooltip;
    $vars['wgTippingOver']['preloadMissingPageTooltip'] = $this->mPreloadMissingPageTooltip;
    $vars['wgTippingOver']['preloadEmptyPageNameTooltip'] = $this->mPreloadEmptyPageNameTooltip;
    $vars['wgTippingOver']['useTwoRequestProcess'] = $this->mUseTwoRequestProcess;
  }
  
  /**
   * Recurvise function that assigns all page ids in the given category and its subcategories to the $mCategoryLookup
   * array, allowing for category filtering to be done without querying the database later.
   * @global Array $wgtoNamespacesWithTooltips What namespaces have tooltips enabled for links into them.
   * @param string $title The appropriate value of page_name or cl_to for the category in the database.
   */
  private function populateLookupFromCategory( $title ) {
    global $wgtoNamespacesWithTooltips;
    
    $dbr = wfGetDB( DB_SLAVE );
    
    $result = $dbr->select( Array( 'page', 'categorylinks' ),
                            Array( 'page_id', 'page_namespace', 'page_title' ),
                            Array( 'cl_to' => $title, 'cl_from = page_id' ),
                            __METHOD__,
                            Array( 'ORDER BY' => 'page_id' )
                          );
    
    foreach ( $result as $row ) {
      if ( $row->page_id !== null && 
           $row->page_id !== 0 &&
           $row->page_namespace !== null &&
           array_key_exists( intval( $row->page_namespace ), $wgtoNamespacesWithTooltips ) &&
           $wgtoNamespacesWithTooltips[intval( $row->page_namespace )] 
         ) {
        $this->mCategoryFilterLookup[] = intval( $row->page_id );
      }
      if ( intval( $row->page_namespace ) === NS_CATEGORY ) {
        $this->populateLookupFromCategory( $row->page_title );
      }
    }
  }
  
  /**
   * Gets a Title object for the appropriate filter category, or returns null if there is an error getting it. Note
   * this function does not check to see if category filtering is disabled.
   * @global int $wgtoCategoryFiltering Whether category filtering is enabled at all and when and how it happens.
   * @global string $wgtoEnablingCategory The title of the category that enable tooltips.
   * @global string $wgtoDisablingCategory The title of the category that disables tooltips
   * @return The Title object of the appropriate root category or null if there is an error generating it..
   */
  public static function getFilterCategoryTitle() {
    global $wgtoCategoryFilterMode, $wgtoEnablingCategory, $wgtoDisablingCategory;
    
    if ( $wgtoCategoryFilterMode === TO_ENABLING ) {
      return Title::newFromText( $wgtoEnablingCategory, NS_CATEGORY );
    } else {
      return Title::newFromText( $wgtoDisablingCategory, NS_CATEGORY );
    }
  }
  
  /**
   * Initiates the prefetch of page ids for category filtering should the extension configuration have it properly
   * enabled in the correct mode.
   */
  private function populateLookup() {
    $title = self::getFilterCategoryTitle();
    if ( $title !== null ) {
      $this->populateLookupFromCategory( $title->getDBKey() );
      sort( $this->mCategoryFilterLookup, SORT_NUMERIC );
    }
  }
  
  /**
   * Performs a binary search of the category filter lookup array for the given item. Returns an array with members
   * indicating if the item was found and the index of the last item compared.
   * @param int $id The page id to search for.
   * @return bool True if the id was found, false if not.
   */
  private function isInCategoryLookup( $id ) {
    $min = 0;
    $max = count( $this->mCategoryFilterLookup ) - 1;
    
    while ( $min <= $max ) {
      $mid = intval( ( $min + $max ) / 2 );
      $midId = $this->mCategoryFilterLookup[$mid];
      
      if ( $midId === $id ) {
        return true;
      } else if ( $midId > $id ) {
        $max = $mid - 1;
      } else {
        $min = $mid + 1;
      }
    }
    
    return false;
  }
  
  /**
   * Returns true if the given namespace index and title pair passes the category filtering enabled by the current
   * configuration, or always passes true if no such filtering is enabled or if late checks are enabled.
   * @global int $wgtoCategoryFiltering Whether category filtering is enabled at all and when and how it happens.
   * @global int $wgtoCategoryFilterMode Whether the category enables or disables the tooltip.
   * @param Title $title The title to search for.
   * @return bool False if it fails the filter and should have its tooltip disabled, true to continue processing.
   */
  private function passesCategoryFilter( $title ) {
    global $wgtoCategoryFiltering, $wgtoCategoryFilterMode;
    
    switch ( $wgtoCategoryFiltering ) {
      case TO_PREQUERY:
        if ( $this->isInCategoryLookup( $title->getArticleID() ) ) {
          return ($wgtoCategoryFilterMode === TO_ENABLING);
        } else {
          return ($wgtoCategoryFilterMode === TO_DISABLING);
        }
      case TO_RUN_EARLY:
        $id = $title->getArticleID();
        if ( array_key_exists( $id, $this->mCategoryFilterCache ) ) {
          return $this->mCategoryFilterCache[$id];
        } else {
          $category = self::getFilterCategoryTitle()->getText();
          $finder = new CategoryFinder;
          $finder->seed( Array( $id ), Array( $category ) );
          if ( count( $finder->run() ) === 1 ) {
            return ( $this->mCategoryFilterCache[$id] = ($wgtoCategoryFilterMode === TO_ENABLING) );
          } else {
            return ( $this->mCategoryFilterCache[$id] = ($wgtoCategoryFilterMode === TO_DISABLING) );
          }
        }
      default:
        return true;
    }
  }
  
  /**
   * Converts all nonnumeric, nonalphabetic character or any character (actually, byte) outside the ASCII set to a hex 
   * representation beginning with an underscore and ending with a dash. Used primarily for generating unique element 
   * ids from page titles. This must produce results consistent with otWikiTooltips.encodeAllSpecials in 
   * otWikiTooltips.js. 
   * @param string $unencoded The unencoded string.
   * @return string The encoded string.
   */
  private function encodeAllSpecial( $unencoded ) {
    $encoded = ""; 
    $c;
    $safeChars = "/[0-9A-Za-z]/";
    for( $i = 0; $i < strlen( $unencoded ); $i++ ) {
      $c = $unencoded[$i];
      if ( preg_match( $safeChars, $c ) === 1 ) {
        $encoded = $encoded . $c;
      } else {
        $encoded = $encoded . '_' . dechex( ord($c) ) . '-';
      }
    }
    return $encoded;
  }
  
  /**
   * Returns the wikitext used to retrieve the appropriate content for a given tooltip page.
   * @param Title $title A title object for the given tooltip page.
   * @return string The wikitext to parse to get the appropriate content.
   */
  public static function getTooltipWikiText( $title ) {
    if ( $title !== null ) {
      if ( $title->getNamespace() === NS_FILE ) {
        return '[[' . $title->getPrefixedText() . '|link=]]';
      } else {
        return '{{:' . $title->getPrefixedText() . '}}';
      }
    } else {
      return null;
    }
  }
  
  /**
   * Gets a Parser and ParserOptions instance by cloning the main parser, using the same approach as MediaWiki's
   * internal messages API.
   * @global Parser $wgParser The main parser object.
   * @global Array $wgParserConf The main parser configuration.
   */
  private function initializeParser() {
    global $wgParser, $wgParserConf;
    
    if ( $this->mParser === null && isset( $wgParser ) ) {
      $wgParser->firstCallInit();
      $class = $wgParserConf['class'];
      if ( $class == 'ParserDiffTest' ) {
        $this->mParser = new $class( $wgParserConf );
      } else {
        $this->mParser = clone $wgParser;
      }
    }
    
    if ( $this->mParserOptions === null ) {
      $this->mParserOptions = new ParserOptions;
      $this->mParserOptions->setEditSection( false );
    }
  }
  
  /**
   * Parses the content of the given page name to HTML, or returns null if the given page name is null, does not exist,
   * is in some way invalid, or parses just to whitespace.
   * @param string $titleText The title in string form.
   * @param string $html Returns the page content parsed to HTML or null.
   * @param bool $doPreload Returns true if the tooltip show use preload logic or false otherwise.
   */
  private function parseTooltip( $titleText, &$html, &$doPreload ) {
    if ( $this->mParser !== null && $titleText !== null && trim( $titleText ) !== '' ) {
      $title = Title::newFromText( $titleText );
      $out = $this->mParser->parse( self::getTooltipWikiText( $title ), $title, $this->mParserOptions, true );
      $html = $out->getText();
      $doPreload = ( $title->getNamespace() === NS_FILE );
      if ( trim( $html ) === '' ) {
        $html = null;
        $doPreload = false;
      }
    } else {
      $html =  null;
      $doPreload = false;
    }
  }
  
  /**
   * Performs potentially expensive initialization tasks which require parser or database access.
   * @global string $wgtoLoadingTooltip The page name for the loading tooltip content.
   * @global string $wgtoMissingPageTooltip The page name for the missing tooltip page content.
   * @global string $wgtoEmptyPageNameTooltip The page for the tooltip content when title parsing gives an empty result.
   * @global bool $wgtoAssumeNonemptyPageTitle Whether to assume an empty page title will not be returned.
   * @global int $wgtoPageTitleParse When to do the parsing of the target title into the tooltip title page.
   * @global int $wgtoExistsCheck Whether and when to do a check to see if the tooltip page exists.
   * @global int $wgtoCategoryFiltering Whether category filtering is enabled at all and when and how it happens.
   */
  private function performDelayedInitialization() {
    global $wgtoLoadingTooltip, $wgtoMissingPageTooltip, $wgtoEmptyPageNameTooltip;
    global $wgtoPageTitleParse, $wgtoAssumeNonemptyPageTitle, $wgtoExistsCheck, $wgtoCategoryFiltering;
    
    $this->initializeParser();
    $this->parseTooltip( $wgtoLoadingTooltip, 
                         $this->mLoadingTooltipHtml, 
                         $this->mPreloadLoadingTooltip 
                       );
    $this->parseTooltip( $wgtoMissingPageTooltip, 
                         $this->mMissingPageTooltipHtml, 
                         $this->mPreloadMissingPageTooltip 
                       );
    if ( !$wgtoAssumeNonemptyPageTitle ) {
      $this->parseTooltip( $wgtoEmptyPageNameTooltip,
                           $this->mEmptyPageNameTooltipHtml, 
                           $this->mPreloadEmptyPageNameTooltip 
                         );
    } else {
      $this->mEmptyPageNameTooltipHtml = null;
      $this->mPreloadEmptyPageNameTooltip = false;
    }
    
    $this->mUseTwoRequestProcess = false;
    if ( $this->mLoadingTooltipHtml !== null ) {
      if ( $wgtoPageTitleParse === TO_RUN_LATE && 
           $wgtoExistsCheck !== TO_DISABLE && 
           $this->mMissingPageTooltipHtml === null
         ) {
        $this->mUseTwoRequestProcess = true;
      } else if ( $wgtoPageTitleParse === TO_RUN_LATE &&
                  !$wgtoAssumeNonemptyPageTitle && 
                  $this->mEmptyPageNameTooltipHtml === null 
                ) {
        $this->mUseTwoRequestProcess = true;
      } else if ( $wgtoExistsCheck === TO_RUN_LATE && $this->mMissingPageTooltipHtml === null ) {
        $this->mUseTwoRequestProcess = true;
      } else if ( $wgtoCategoryFiltering == TO_RUN_LATE ) {
        $this->mUseTwoRequestProcess = true;
      }
    }
    
    if ( $wgtoCategoryFiltering === TO_PREQUERY ) {
      $this->populateLookup();
    }
    
    $this->mIsLinkProcessingInitialized = true;
  }
  
  /**
   * This function performs the server-side checks enabled by the current configuration to determine if a given link
   * does not have a tooltip and returns null. Otherwise, it returns an array of information needed for both displaying
   * the tooltip client-side and running any further checks there to see if a tooltip is available for the link.
   * @global Array $wgtoNamespacesWithTooltips What namespaces have tooltips enabled for links into them.
   * @global int $wgtoPageTitleParse When to do the parsing of the target title into the tooltip title page.
   * @global int $wgtoExistsCheck Whether and when to do a check to see if the tooltip page exists.
   * @param Title $target The target page of the link.
   * @return Array null if the link should not have a tooltip, or an array of information if it might get one.
   */
  private function runEarlyTooltipChecks( $target ) {
    global $wgtoNamespacesWithTooltips, $wgtoPageTitleParse, $wgtoExistsCheck;
    
    if ( array_key_exists( $target->getNamespace(), $wgtoNamespacesWithTooltips ) &&
         $wgtoNamespacesWithTooltips[$target->getNamespace()] &&
         $this->passesCategoryFilter( $target ) 
       ) {
      $tooltipTitle = null;
      $setupInfo = Array();
      
      if ( $wgtoPageTitleParse === TO_RUN_EARLY ) {
        $tooltipTitleText = wfMessage( 'to-tooltip-page-name' )->params( $target->getPrefixedText() )->parse();
        if ( trim( $tooltipTitleText ) !== "" ) {
          $tooltipTitle = Title::newFromText( $tooltipTitleText );
          if ( $tooltipTitle !== null ) {
            if ( $wgtoExistsCheck === TO_RUN_EARLY ) {
              if ( $tooltipTitle->exists() ) {
                $setupInfo['missingPage'] = false;
                $setupInfo['isImage'] = ( $tooltipTitle->getNamespace() === NS_FILE );
              } else if ( $this->mMissingPageTooltipHtml !== null ) {
                $setupInfo['missingPage'] = true;
                $setupInfo['isImage'] = $this->mPreloadMissingPageTooltip;
              } else {
                return null; // the tooltip page is missing, and there's no tooltip for this situation 
              }
            } else {
              $setupInfo['isImage'] = ( $tooltipTitle->getNamespace() === NS_FILE );
            }
            $setupInfo['tooltipTitle'] = $tooltipTitle->getPrefixedText();
            $setupInfo['emptyPageName'] = false;
          } else {
            return null; // not an empty page name, but Title couldn't process it, so no tooltip.
          }
        } else if ( $this->mEmptyPageNameTooltipHtml !== null ) {
          $setupInfo['emptyPageName'] = true;
          $setupInfo['isImage'] = $this->mPreloadEmptyPageNameTooltip;
        } else {
          return null; // the tooltip title parsed to an empty value, and we have no tooltip for it
        }
      }
      
      return $setupInfo;
    } else {
      return null; // didn't pass category filter or namespace check, so no tooltip.
    }
  }
  
  /**
   * Attached to the LinkEnd hook of the MediaWiki linker, this function will add appropriate data elements and other
   * attributes to any link that should have or might have a tooltip, preparing it for the client-side script to
   * finish the job.
   * @param mixed $dummy Placeholder for removed skin parameter. Not used.
   * @param Title $target The title of the target page.
   * @param Array $options An array of link options to get or set.
   * @param string $html The inner content of the <a> tag.
   * @param Array $attribs The attributes of the <a> tag and their values.
   * @param string $ret Alternate HTML to return rather than the <a> tag the linker would generate.
   */
  public function checkAndAttachTooltip( $dummy, $target, $options, &$html, &$attribs, &$ret ) {   
    if ( !$this->mIsLinkProcessingInitialized ) {
      $this->performDelayedInitialization();
    }
    
    if ( $target !== null ) {
      $setupInfo = $this->runEarlyTooltipChecks( $target );

      if ( $setupInfo !== null ) {
        $tooltipId = $this->encodeAllSpecial( $target->getPrefixedText() );
        if ( array_key_exists( 'class', $attribs ) ) {
          $attribs['class'] .= ' to_hasTooltip';
        } else {
          $attribs['class'] = 'to_hasTooltip';
        }
        $attribs['data-to-id'] = $tooltipId;
        $attribs['data-to-target-title'] = $target->getPrefixedText();
        if ( $setupInfo['tooltipTitle'] !== null ) {
          $attribs['data-to-tooltip-title'] = $setupInfo['tooltipTitle'];
        }
        if ( array_key_exists( 'isImage', $setupInfo ) ) {
          $attribs['data-to-is-image'] = $setupInfo['isImage'] ? 'true' : 'false';
        }
        if ( array_key_exists( 'emptyPageName', $setupInfo ) ) {
          $attribs['data-to-empty-page-name'] = $setupInfo['emptyPageName'] ? 'true' : 'false';
        } else if ( array_key_exists( 'missingPage', $setupInfo ) ) {
          $attribs['data-to-missing-page'] = $setupInfo['missingPage'] ? 'true' : 'false';
        }
        $html = $html; // for some reason, this is solving a problem with the added attribs not appearing
                       // I have no idea why this works. I think the original problem was probably with a cache,
                       // but it's harmless even if stupid, so leaving it in for now.
      }
    }
    
    return true;
  }
}