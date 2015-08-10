<?php
/**
 * An extension allowing editors to create tooltips for wiki links using either wiki pages or uploaded images.
 *
 * @addtogroup Extensions
 *
 * @link 
 *
 * @author Eyes <eyes@aeongarden.com>
 * @copyright Copyright � 2015 Eyes
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

// If this is run directly from the web die as this is not a valid entry point.
if ( !defined( 'MEDIAWIKI' ) ) {
    echo "This is a MediaWiki extension and cannot run standalone.\n";
    die( -1 );
}

// Extension credits.
$wgExtensionCredits[ 'other' ][] = array(
  'name'           => 'TippingOver',
  'url'            => 'http://sw.aeongarden.com/wiki/TippingOver',
  'descriptionmsg' => 'tippingover-desc',
  'author'         => '[http://www.mediawiki.org/wiki/User:OoEyes Shawn Bruckner]',
  'version'        => '0.53',
);

/**
 * Set up constants.
 */
define( "TO_DISABLE", 0 ); // Configuration option for disabling certain features.
define( "TO_PREQUERY", 1 ); // Configuration option for category filter processing
define( "TO_RUN_EARLY", 2 ); // Configuration option for processing certain features during page loads.
define( "TO_RUN_LATE", 3 ); // Configuration option for processing certain features on link mouseover events.
define( "TO_DISABLING", false ); // Configuration option for making the category filter disable tooltips on links.
define( "TO_ENABLING", true ); // Configuration option for making the category filter enable tooltipls on links.

/**
 * Options:
 *
 * $wgtoEnableInNamespaces --
 *       This defines what namespaces tooltips are actually displayed in. When viewing pages in namespaces where this
 *       is set to false, no internal links on the page will display tooltips.
 */
if ( !isset( $wgtoEnableInNamespaces ) ) {
  $wgtoEnableInNamespaces = Array();
}
$wgtoEnableInNamespaces = array_replace( Array( NS_MAIN => true,
                                                NS_TALK => false,
                                                NS_USER => true,
                                                NS_USER_TALK => false,
                                                NS_PROJECT => true,
                                                NS_PROJECT_TALK => false,
                                                NS_IMAGE => false,
                                                NS_IMAGE_TALK => false,
                                                NS_MEDIAWIKI => false,
                                                NS_MEDIAWIKI_TALK => false,
                                                NS_TEMPLATE => false,
                                                NS_TEMPLATE_TALK => false,
                                                NS_HELP => false,
                                                NS_HELP_TALK => false,
                                                NS_CATEGORY => true,
                                                NS_CATEGORY_TALK => false,
                                              ),
                                         $wgtoEnableInNamespaces
                                       );

/**
 * $wgtoNamespacesWithTooltips --
 *       This defines which internal links to which namespaces will have tooltips enabled  Links to pages in
 *       namespaces where this is set to false, or which do not appear in the array, will not have tooltips displayed.
 */
if ( !isset( $wgtoNamespacesWithTooltips ) ) {
  $wgtoNamespacesWithTooltips = Array();
}
$wgtoNamespacesWithTooltips = array_replace( Array( NS_MAIN => true,
                                                    NS_TALK => false,
                                                    NS_USER => true,
                                                    NS_USER_TALK => false,
                                                    NS_PROJECT => false,
                                                    NS_PROJECT_TALK => false,
                                                    NS_IMAGE => false,
                                                    NS_IMAGE_TALK => false,
                                                    NS_MEDIAWIKI => false,
                                                    NS_MEDIAWIKI_TALK => false,
                                                    NS_TEMPLATE => false,
                                                    NS_TEMPLATE_TALK => false,
                                                    NS_HELP => false,
                                                    NS_HELP_TALK => false,
                                                    NS_CATEGORY => false,
                                                    NS_CATEGORY_TALK => false,
                                                  ),
                                             $wgtoNamespacesWithTooltips
                                           );

/**
 * $wgtoLoadingTooltip --
 *       This defines what page, if any, to use as a loading tooltip. In other words, this is what will be displayed
 *       while the actual tooltip for the page is being requested from the server. To disable loading tooltips 
 *       completely, set this to null. The loading tooltip is displayed while waiting for the server to return the 
 *       requested tooltip after a user hovers over a link.
 * 
 *       This can also be disabled by blanking or deleting the page in question, though this is slightly less 
 *       efficient as there is more overhead involved in checking that, but it does allow it to be enabled more easily
 *       later on.
 * 
 *       WARNING: Be careful when using loading tooltips under these situations:
 *           * $wgtoParsePageTitle set to TO_DO_LATE with no valid empty page name tooltip unless 
 *             $wgAssumeNonemptyPageTitle is set to true (but see risks with that setting),
 *           * $wgtoParsePageTitle set to TO_DO_LATE with no valid missing page tooltip unless $wgtoExistsCheck is set
 *             to TO_DISABLE (but see risks with that setting), 
 *           * $wgtoExistsCheck set to TO_DO_LATE with no valid missing page tooltip, or
 *           * $wgtoCategoryFiltering set to TO_DO_LATE under any circumstance.
 * 
 *       In these configurations, TippingOver does not know if there is a tooltip to show when a user hovers over a link
 *       and sends a request just to determine if it makes sense to show the loading tooltip first, and if so, it then 
 *       sends a separate request to actually load the tooltip. This avoids showing a loading tooltip that just 
 *       disappears, but the loading tooltip itself will be delayed in showing up and tooltips themselves will load more 
 *       slowly.
 */
if ( !isset( $wgtoLoadingTooltip ) ) {
  $wgtoLoadingTooltip = "MediaWiki:To-loading-tooltip";
}

/**
 * $wgtoMissingPageTooltip --
 *       This defines what page, if any, to use as a tooltip if the page identified by processing 
 *       MediaWiki:To-tooltip-page-name doesn't exist. To disable this entirely, set this to null.
 * 
 *       This can also be disabled by blanking or deleting the page in question, though this is slightly less 
 *       efficient as there is more overhead involved in checking that, but it does allow it to be enabled more easily
 *       later on.
 * 
 *       WARNING: See warning under $wgtoLoadingTooltip below before disabling this in any way.
 */
if ( !isset( $wgtoMissingPageTooltip ) ) {
  $wgtoMissingPageTooltip = "MediaWiki:To-missing-page-tooltip";
}

/**
 * $wgtoEmptyPageNameTooltip --
 *       This defines what page, if any, to use as a tooltip if processing MediaWiki:To-tooltip-page-name results in an
 *       empty value being returned. To disable this entirely, set this to null.
 * 
 *       This can also be disabled by blanking or deleting the page in question, though this is slightly less 
 *       efficient as there is more overhead involved in checking that, but it does allow it to be enabled more easily
 *       later on.
 * 
 *       WARNING: See warning under $wgtoLoadingTooltip below before disabling this in any way.
 */
if ( !isset( $wgtoEmptyPageNameTooltip ) ) {
  $wgtoEmptyPageNameTooltip = "MediaWiki:To-empty-page-name-tooltip";
}

/**
 * $wgtoPageTitleParse --
 *       This defines when the title of a link's target should be sent to MediaWiki:To-tooltip-page-name to parse the
 *       appropriate title for the tooltip page.
 * 
 *       Valid values are:
 * 
 *       TO_DO_EARLY : This performs the parse while the page is loading, saving some processing when actually
 *           loading tooltips at the expense of adding some extra processing for links during the page load. How much
 *           of an impact this will have will depend a lot on the logic in MediaWiki:To-tooltip-page-name.
 *           Expensive processing in that function could present a danger of causing link-heavy pages to time out, or
 *           at least load very slowly, but there are tradeoffs to doing the check late.
 * 
 *       TO_DO_LATE : This performs the parse after the user movses over a link during the actual tooltip load.
 *           This may be a good idea if MediaWiki:To-tooltip-page-name contains expensive logic, pages are particularly 
 *           link heavy, and/or category filtering isn't an option or doesn't significantly reduce the number of links
 *           to check.
 * 
 *           WARNING: See warning under $wgtoLoadingTooltip below before using TO_DO_LATE.
 */
if ( !isset( $wgtoPageTitleParse ) ) {
  $wgtoPageTitleParse = TO_RUN_EARLY;
}

/**
 * $wgtoAssumeNonemptyPageTitle --
 *       This determines if TippingOver should assume MediaWiki:To-tooltip-page-name will always return a page name.
 * 
 *       Valid values are:
 * 
 *       true : This will disable the empty page tooltip defined by $wgEmptyPageNameTooltip even if it set, so no
 *           tooltip is shown if MediaWiki:To-tooltip-page-name should return an empty value.
 * 
 *           WARNING: As the name suggests, this causes TippingOver to assume MediaWiki:To-tooltip-page-name won't
 *           return an empty page name. When $wgtoPageTitleParse to set to TO_RUN_LATE while there is a valid loading
 *           tooltip, depending on other configuration settings, the loading tooltip may be displayed just to
 *           disappear if MediaWiki:To-tooltip-page-name does return an empty value. Therefore, this setting is 
 *           recommended only if MediaWiki:To-tooltip-page-name is guaranteed not to return an empty value.
 *           
 *           See $wgtoLoadingTooltip for more information.
 * 
 *       false : This causes TippingOver to assume MediaWiki:To-tooltip-page-name may return an empty value, and so
 *           $wgtoEmptyPageNameTooltip is enabled if valid. Additionally, when $wgtoPageTitleParse to set to 
 *           TO_RUN_LATE while there's a valid loading tooltip but no valid empty page tooltip, TippingOver sends a 
 *           request to the server to get the page name before showing the loading tooltip. This is slower, but prevents
 *           the loading tooltip from being shown when there is no tooltip to show.
 */
if ( !isset( $wgtoAssumeNonemptyPageTitle ) ) {
  $wgtoAssumeNonemptyPageTitle = false;
}

/**
 * $wgtoExistsCheck --
 *       This defines whether or not a check is made that the page identified by processing
 *       MediaWiki:To-tooltip-page-name exists for the given target page of a link, and also when to do it.
 * 
 *       Valid values are:
 * 
 *       TO_DISABLE : This disables the exists check completely. This is useful if the logic in 
 *           MediaWiki:To-tooltip-page-name is guaranteed to return an empty value if the tooltip page doesn't exist,
 *           or it has its own logic for determining the correct default page to use. This option then prevents
 *           redundant processing that could slow down page or tooltip loading.
 * 
 *           WARNING: This can have undesirable results if MediaWiki:To-tooltip-page-name does return a page that
 *           doesn't exist. It may display a red link when hovering over a link, or less seriously, it may display
 *           the loading tooltip only to have it disappear, as this effectively disables $wgMissingPageTooltip as
 *           well.
 * 
 *       TO_DO_EARLY : This performs the exists check while the page is loading, saving some processing when actually
 *           loading tooltips at the expense of adding some extra processing for links during the page load.
 * 
 *           Nore that if $wgtoPageTitleParse is set to TO_RUN_LATE, this is the same as disabling the exists check 
 *           because the tooltip title is not available to be checked during the page load.
 * 
 *       TO_DO_LATE : This performs the exists check after the user movses over a link during the actual tooltip load.
 *           This may be a good idea if pages are particularly link heavy and category filtering isn't an option or
 *           doesn't significantly reduce the number of links to check.
 * 
 *           WARNING: See warning under $wgtoLoadingTooltip below before using TO_DO_LATE.
 */
if ( !isset( $wgtoExistsCheck ) ) {
  $wgtoExistsCheck = TO_RUN_EARLY;
}

/**
 * $wgtoCategoryFiltering --
 *       This defines whether or not to either enable or disable tooltips on given links depending on whether their
 *       target page is within the hierarchy of a given category. It also determines when and how such a check is
 *       performed.
 * 
 *       Valid values are:
 * 
 *       TO_DISABLE : This disables category filtering completely.
 * 
 *       TO_PREQUERY : This prefetches the page ids within the relevant category and all it subcategories and performs 
 *           the filtering while the page is loading. This allows category filtering to then be done with a very quick
 *           lookup as each link is processed. Provided the hierarchy is not complex and the number of pages within the
 *           category structure is not excessive, this will probably perform better on most pages than TO_DO_EARLY.
 * 
 *       TO_DO_EARLY : This performs the category filtering while the page is loading as each link is processed. It
 *           must check the database for every new page it encounters, though, which may noticeably slow down the
 *           loading of link-heavy pages. TO_PREQUERY or TO_DO_LATE are usually going to be better options in terms of
 *           performance.
 * 
 *       TO_DO_LATE : This performs the category filtering after the user hovers over a link. Note that in this mode,
 *           a request still gets sent to the server every time a user hovers over a link, even if no tooltip ends up
 *           showing up. The benefit is that category filtering will not slow down page loads in this mode.
 * 
 *           WARNING: See warning under $wgtoLoadingTooltip below before using TO_DO_LATE.
 */
if ( !isset( $wgtoCategoryFiltering ) ) {
  $wgtoCategoryFiltering = TO_DISABLE;
}

/**
 * $wgtoCategoryFilterMode --
 *       Only applies if $wgtoCategoryFiltering is not set to TO_DISABLE
 * 
 *       This defines whether or not links will normally have their tooltips enabled. This is overridden by *false*
 *       values from $wgtoNamespacesWithTooltips, but this will override *true* values.
 * 
 *       Valid values are:
 * 
 *       TO_ENABLING : Links will not have tooltips unless their target page is within the category defined by 
 *           $wgtoEnablingCategory or one of its subcategories.
 * 
 *       TO_DISABLING: Links will get tooltips by default unless their target page is within the category defined
 *           by $wgtoDisablingCategory or one of its subcategories.
 */
if ( !isset( $wgtoCategoryFilterMode ) ) {
  $wgtoCategoryFilterMode = TO_ENABLING;
}

/**
 * $wgtoEnablingCategory --
 *       Only applies if $wgtoUseCategoryFiltering is not set to TO_DISABLE and $wgtoCategoryFilterMode is set to
 *       TO_ENABLING.
 * 
 *       When those settings are applied, a link only gets a tooltip when its target page is in the category defined
 *       by this setting or one of its subcategories. The Category: namespace is optional when defining the category
 *       in this setting.
 */
if ( !isset( $wgtoEnablingCategory ) ) {
  $wgtoEnablingCategory = "Has tooltips enabled";
}

/**
 * $wgtoDisablingCategory --
 *       Only applies if $wgtoUseCategoryFiltering is not set to TO_DISABLE and $wgtoCategoryFilterMode is set to
 *       TO_DISABLING.
 * 
 *       When those settings are applied, a link will not get a tooltip when its target page is in the category defined
 *       by this setting or one of its subcategories. The Category: namespace is optional when defining the category
 *       in this setting.
 */
if ( !isset( $wgtoDisablingCategory ) ) {
  $wgtoDisablingCategory = "Has tooltips disabled";
}
  
/**
 * Perform setup tasks.
*/
$wgMessagesDirs['tippingover'] = dirname ( __FILE__ ) . '/i18n';

$wgAutoloadClasses['WikiTooltips'] = dirname( __FILE__ ) . '/includes/WikiTooltips.php';
$wgAutoloadClasses['ApiQueryTooltip'] = dirname( __FILE__ ) . '/includes/ApiQueryTooltip.php';

$wgHooks['BeforeInitialize'][] = Array( WikiTooltips::getInstance(), 'initializeHooksAndModule' );

$wgAPIModules['tooltip'] = "ApiQueryTooltip";

$wgResourceModules['ext.TippingOver.wikiTooltips'] = Array(
  'scripts' => 'includes/toWikiTooltips.js',
  'styles' => 'includes/toWikiTooltips.css',
  'localBasePath' => dirname( __FILE__ ),
  'remoteExtPath' => 'TippingOver',
);