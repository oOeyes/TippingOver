/**
 * This singleton class ...
 *
 * @author Eyes <eyes@aeongarden.com>
 * @copyright Copyright � 2015 Eyes
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

var toWikiTooltips = toWikiTooltips || {

  config : null,
  tooltipClassPrefix : 'to-tooltip-',
  preloadClassPrefix : 'to-preload-',
  loadingTooltipHtml : null,
  missingPageTooltipHtml : null,
  emptyPageNameTooltipHtml : null,
  tooltipHeight : null,
  visibleTooltipId : null,
  pageX : 0,
  pageY : 0,

  /**
   * Converts all nonnumeric, nonalphabetic character or any character (actually, byte) outside the ASCII set to a hex 
   * representation beginning with an underscore and ending with a dash. Used primarily for generating unique element 
   * ids from page titles. This must produce results consistent with WikiTooltips->encodeAllSpecials() in 
   * wikiTooltips.php.
   * @param {String} unencoded The unencoded string.
   * @returns {String} The encoded string.
   */
  encodeAllSpecial : function( unencoded ) {
    var encoded = ""; 
    var c;
    var safeChars = /[0-9A-Za-z]/;
    for( var i = 0; i < unencoded.length; i++ ) {
      c = unencoded.charAt(i);
      if ( safeChars.test( c ) ) {
        encoded = encoded + c;
      } else {
        encoded = encoded + '_' + c.charCodeAt().toString(16) + '-';
      }
    }
    return encoded;
  },

  /**
   * Performs some basic initialization tasks, including preloading the loading tooltip image if there is one.
   */
  beginInitialize : function() {
    toWikiTooltips.config = mw.config.get( 'wgTippingOver' );
    
    if ( toWikiTooltips.loadingTooltip !== null && toWikiTooltips.config.preloadLoadingTooltip ) {
      var preloadBox = $( '<div />' );
      preloadBox.css( 'display', 'none' );
      $( 'body' ).append( preloadBox );
      preloadBox.html( toWikiTooltips.config.loadingTooltip );
      preloadBox.find( 'img' )
        .one( 'load', toWikiTooltips.finishInitialize )
        .each( function() {
          if ( this.complete ) {
            $( this ).trigger( 'load' );
          }
        } );
    } else {
      toWikiTooltips.finishInitialize( );
    }
  },
  
  /**
   * Performs additional initialization tasks, done after preloading the loading tooltip image if there is one or
   * just after beginInitialize() is finished.
   */
  finishInitialize : function() {
    $( window ).resize( toWikiTooltips.resizeVisibleTooltip );
    
    $( '.to_hasTooltip' ).each( function() {
      toWikiTooltips.createTooltipAndPreloadElement( $( this ) );
      $( this )
        .on( 'mouseover', toWikiTooltips.showTooltip )
        .on( 'mousemove', toWikiTooltips.moveTooltip )
        .on( 'mouseout', toWikiTooltips.hideTooltip );
    } );
  },
  
  /**
   * Creates a tooltip and possibly a preload box for a given link according to the appropriate data attributes set
   * on the link.
   * @param {Element} link The link to setup tooltip and preload boxes for.
   */
  createTooltipAndPreloadElement : function( link ) {
    var linkData = link.data();
    
    if ( $( '#' + toWikiTooltips.tooltipClassPrefix + linkData.toId ).length === 0 ) {
      var tooltipBox = $( '<div />');
      tooltipBox.addClass( 'to_tooltip' );
      tooltipBox.attr( 'id', toWikiTooltips.tooltipClassPrefix + linkData.toId );
      tooltipBox.css( { 'display' : 'none', 'position' : 'absolute', 'z-index' : '30000' } );
      tooltipBox
        .on( 'mouseover', toWikiTooltips.moveTooltip )
        .on( 'mousemove', toWikiTooltips.moveTooltip )
        .on( 'mouseout', toWikiTooltips.moveTooltip );
      var tooltipData = tooltipBox.data();
      tooltipData.toChecking = false;
      tooltipData.toLoading = false;
      tooltipData.toLoaded = false;
      tooltipData.toShowWhenLoaded = false;
      if ( "toTargetTitle" in linkData ) {
        tooltipData.toTargetTitle = linkData.toTargetTitle;
      }
      if ( "toIsImage" in linkData ) {
        tooltipData.toIsImage = linkData.toIsImage;
      }
      if ( "toMissingPage" in linkData ) {
        tooltipData.toMissingPage = linkData.toMissingPage;
      }
      if ( "toEmptyPageName" in linkData ) {
        tooltipData.toEmptyPageName = linkData.toEmptyPageName;
      }
      $( 'body' ).append( tooltipBox );

      if ( !("toIsImage" in linkData) || linkData.toIsImage ) {
        var preloadBox = $( '<div />');
        preloadBox.attr( 'id', toWikiTooltips.preloadClassPrefix + linkData.toId );
        preloadBox.css( 'display', 'none' );
        $( 'body' ).append( preloadBox );

        if ( tooltipData.toMissingPage ) {
          toWikiTooltips.beginUpdateTooltip( linkData.toId, 
                                             toWikiTooltips.config.missingPageTooltip,
                                             !toWikiTooltips.config.preloadMissingPageTooltip
                                           );
        } else if ( tooltipData.toEmptyPageName ) {
          toWikiTooltips.beginUpdateTooltip( linkData.toId, 
                                             toWikiTooltips.config.emptyPageNameTooltip,
                                             !toWikiTooltips.config.preloadEmptyPageNameTooltip
                                           );
        }
        $( 'body' ).append( preloadBox );
      }
    }
  },
  
  /**
   * Removes a tooltip and preload box along with the events for the given link.
   * @param {Element} The link to remove events from.
   * @param {string} id The unique id fragment for the tooltip box.
   */
  removeTooltip : function( link, id ) {
    $( '#' + toWikiTooltips.preloadClassPrefix + id ).remove();
    $( '#' + toWikiTooltips.tooltipClassPrefix + id ).remove();
    
    $( link )
      .off( 'mouseover', toWikiTooltips.showTooltip )
      .off( 'mousemove', toWikiTooltips.moveTooltip )
      .off( 'mouseout', toWikiTooltips.hideTooltip );
  },
  
   isTooltipToShow : function( jsonData ) {
    if ( toWikiTooltips.config.doLateCategoryFiltering && jsonData.passesCategoryFilter === 'false' ) {
      return false;
    } else if ( toWikiTooltips.config.doLateExistsCheck && jsonData.exists === 'false' ) {
      return ( toWikiTooltips.config.missingPageTooltip !== null );
    } else if ( toWikiTooltips.config.doLatePageTitleParse && "title" in jsonData && jsonData.title.trim() === '' ) {
      return ( toWikiTooltips.config.emptyPageNameTooltip !== null );
    } else if ( !("text" in jsonData) || jsonData.text['*'].trim() === '' ) {
      return false;
    } else {
      return true;
    }
  },
  
  /**
   * After all needed information has been pulled from the server for a valid tooltip, this function will begin the
   * process of updating the tooltip as needed, removing the preload box for any tooltip that won't be using it.
   * It initiates the preload for image tooltips that do use it, which will then call finishUpdateTooltip() to do the
   * last update tasks, or calls that function immediately for other tooltips.
   * @param {string} id The unique id fragment for the tooltip box.
   * @param {type} html The appropriate content for the tooltip box.
   * @param {type} removePreload true if this tooltip should not preload and should not have a preload box.
   */
  beginUpdateTooltip : function( id, html, removePreload ) {
    var preloadBox = $( '#' + toWikiTooltips.preloadClassPrefix + id );
    
    if ( removePreload ) {
      preloadBox.remove();
      preloadBox = null;
    }
    
    if ( preloadBox !== null && preloadBox.length === 1 ) {
      preloadBox.html( html );
      preloadBox.find( 'img' ).one( 'load', 
                                    { id : id, html: html }, 
                                    function( event ) { 
                                      toWikiTooltips.finishUpdateTooltip( event.data.id, event.data.html )
                                    }
      ).one( 'error', 
             { id : id }, 
             function( event ) {
               toWikiTooltips.resetTooltip( event.data.id );
             } 
      ).each( function() {
        if ( this.complete ) {
          $( this ).trigger( 'load', { id : id, html: html } );
        }
      } );
      setTimeout( function () {
        var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
        if ( tooltipBox.data( 'toLoading' ) ) {
          toWikiTooltips.resetTooltip( id );
        }
      }, 60000 );
    } else {
      toWikiTooltips.finishUpdateTooltip( id, html );
    }
  },
  
  /**
   * Finishes tooltip update tasks after any needed requests, and after a preload if that should happen. Shows the
   * tooltip if it should be shown when loaded.
   * @param {string} id The unique id fragment for the tooltip box.
   * @param {type} html The appropriate content for the tooltip box.
   */
  finishUpdateTooltip : function( id, html ) {
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    var tooltipData = tooltipBox.data( );
    
    tooltipBox.html( html );
    tooltipData.toLoaded = true;
    tooltipData.toLoading = false;
    toWikiTooltips.resizeTooltip( id );
    toWikiTooltips.positionTooltip( id );
    if ( tooltipData.toShowWhenLoaded ) {
      toWikiTooltips.visibleTooltipId = id;
      toWikiTooltips.updateTooltipVisibility();
    }
  },
  
  /**
   * Begins a AJAX request to the tooltip API module implemented by ApiQueryTooltip.
   * @param {string} id The unique id fragment for the tooltip box.
   * @parma {string} targetTitleText The text identifying the title of the target page.
   * @param {string} tooltipTitleText The text identifying the title of the tooltip page. Can pass null.
   * @param {Array} options An array of string options for the API, identifying what to return.
   * @param {function} success A function to call on success.
   */
  beginRequest : function( id, targetTitleText, tooltipTitleText, options, success ) {
    var data = { action: 'tooltip', 
                 format: 'json', 
                 target: targetTitleText,
                 options: options.join( '|' )
              };
    if ( tooltipTitleText !== null ) {
      data['tooltip'] = tooltipTitleText;
    }
    $.ajax( {
      type : 'POST',
      url : mw.util.wikiScript( 'api' ),
      data : data,
      dataType : 'json',
      success : success,
      timeout : 60000,
      error: function() { toWikiTooltips.resetTooltip( id ); }
    } );
  },
  
  /**
   * Sends a request before showing the loading tooltip to gain enough information to determine if the loading tooltip
   * should be displayed. Only happens in certain configurations.
   * @param {Element} link The link that generated the original event.
   * @param {string} id The unique id fragment for the tooltip box.
   * @parma {string} targetTitleText The text identifying the title of the target page.
   * @param {string} tooltipTitleText The text identifying the title of the tooltip page. Can be null.
   */
  beginCheck : function( link, id, targetTitleText, tooltipTitleText ) {
    var options = [];
    if ( toWikiTooltips.config.doLateExistsCheck ) {
      options.push( 'exists' );
    }
    if ( toWikiTooltips.config.doLateCategoryFiltering ) {
      options.push( 'cat' );
    }
    if ( toWikiTooltips.config.doLatePageTitleParse && tooltipTitleText == null ) {
      options.push( 'title' );
      options.push( 'image' );
    }
    toWikiTooltips.beginRequest( id, 
                                 targetTitleText,
                                 tooltipTitleText,
                                 options,
                                 function( jsonData ) { 
                                   toWikiTooltips.finishCheck( link, id, targetTitleText, tooltipTitleText, jsonData ); 
                                 }
    );
  },

  /**
   * Processes the return value of a request started by toWikiTooltips.beginCheck(). If there is a tooltip to load,
   * this will display the loading tooltip and call toWikiTooltips.beginLoadTooltip() to start that.
   * @param (Element} link The link that generated the original event.
   * @param {string} id The unique id fragment for the tooltip box.
   * @parma {string} targetTitleText The text identifying the title of the target page.
   * @param {string} tooltipTitleText The text identifying the title of the tooltip page.
   * @param {object} jsonData The return value of the request.
   */
  finishCheck : function( link, id, targetTitleText, tooltipTitleText, jsonData ) {
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    var tooltipData = tooltipBox.data( );
    
    console.log( !toWikiTooltips.config.doLateCategoryFiltering );
    console.log( jsonData.passesCategoryFilter );
    if ( !toWikiTooltips.config.doLateCategoryFiltering || jsonData.passesCategoryFilter !== 'false' ) {
      if ( toWikiTooltips.config.doLatePageTitleParse && 'tooltipTitle' in jsonData ) {
        if ( jsonData.tooltipTitle.trim() !== '' ) {
          tooltipTitleText = jsonData.tooltipTitle;
          tooltipData.toEmptyPageName = false;
        } else {
          tooltipData.toEmptyPageName = true;
        }
      }
      
      if ( toWikiTooltips.config.doLatePageTitleParse && 'isImage' in jsonData ) {
        tooltipData.toIsImage = ( jsonData.isImage !== 'false' );
      }

      tooltipData.toMissingPage = ( jsonData.exists === 'false' );

      if ( !tooltipData.toMissingPage && !tooltipData.toEmptyPageName ) {
        tooltipData.toLoading = true;
        tooltipData.toChecking = false;
        toWikiTooltips.setToLoadingTooltip( tooltipBox, id );
        toWikiTooltips.beginLoadTooltip( link, id, targetTitleText, tooltipTitleText );
        tooltipBox.show();
        toWikiTooltips.visibleTooltipId = id;
      } else if ( tooltipData.toMissingPage && toWikiTooltips.config.missingPageTooltip !== null ) {
        var html = toWikiTooltips.config.missingPageTooltip.replace( /\$1/g, tooltipData.toTargetTitle );
        toWikiTooltips.beginUpdateTooltip( id, html, !toWikiTooltips.config.preloadMissingPageTooltip );
        tooltipData.toChecking = false;
      } else if ( tooltipData.toEmptyPageName && toWikiTooltips.config.emptyPageNameTooltip !== null ) {
        var html = toWikiTooltips.config.emptyPageNameTooltip.replace( /\$1/g, tooltipData.toTargetTitle );
        toWikiTooltips.beginUpdateTooltip( id, html, !toWikiTooltips.config.preloadEmptyPageNameTooltip );
        tooltipData.toChecking = false;
      } else {
        toWikiTooltips.removeTooltip( link, id );
      }
    } else {
      toWikiTooltips.removeTooltip( link, id );
    }
  },

  /**
   * Sends a request for the tooltip text and optionally performs an exists check, category filter, or tooltip title
   * lookup as well, depending on parameters passed and configuration.
   * @param (Element} link The link that generated the original event.
   * @param {string} id The unique id fragment for the tooltip box.
   * @parma {string} targetTitleText The text identifying the title of the target page.
   * @param {string} tooltipTitleText The text identifying the title of the tooltip page. Can be null.
   */
  beginLoadTooltip : function( link, id, targetTitleText, tooltipTitleText ) {
    var options = [ 'text' ];
    if ( toWikiTooltips.config.doLateExistsCheck ) {
      options.push( 'exists' );
    }
    if ( toWikiTooltips.config.doLateCategoryFiltering ) {
      options.push( 'cat' );
    }
    if ( toWikiTooltips.config.doLatePageTitleParse && tooltipTitleText == null ) {
      options.push( 'title' );
      options.push( 'image' );
    }
    toWikiTooltips.beginRequest( id, 
                                 targetTitleText,
                                 tooltipTitleText,
                                 options,
                                 function( jsonData ) { toWikiTooltips.finishLoadTooltip( link, id, jsonData ); }
    );
  },

  /**
   * Processes the return value of a request started by toWikiTooltips.beginLoadTooltip().
   * @param (Element} link The link that generated the original event.
   * @param {string} id The unique id fragment for the tooltip box.
   * @param {object} jsonData The return value of the request.
   */
  finishLoadTooltip : function( link, id, jsonData ) {
    console.log( "finishLoadTooltip" );
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    var tooltipData = tooltipBox.data( );
    
    if ( !toWikiTooltips.config.doLateCategoryFiltering || jsonData.passesCategoryFilter !== 'false' ) {
      if ( toWikiTooltips.config.doLatePageTitleParse && 'tooltipTitle' in jsonData ) {
        tooltipData.toTooltipTitle = jsonData.tooltipTitle;
        tooltipData.toEmptyPageName = ( jsonData.tooltipTitle.trim() === '' );
      }
      
      if ( toWikiTooltips.config.doLatePageTitleParse && 'isImage' in jsonData ) {
        tooltipData.toIsImage = ( jsonData.isImage !== 'false' );
      }
      
      if ( toWikiTooltips.config.doLateExistsCheck && 'exists' in jsonData ) {
        tooltipData.toMissingPage = ( jsonData.exists === 'false' );
      }
      
      if ( !tooltipData.toMissingPage && !tooltipData.toEmptyPageName ) {
        toWikiTooltips.beginUpdateTooltip( id, jsonData.text['*'], !tooltipData.toIsImage );
      } else if ( tooltipData.toMissingPage && toWikiTooltips.config.missingPageTooltip !== null ) {
        var html = toWikiTooltips.config.missingPageTooltip.replace( /\$1/g, tooltipData.toTargetTitle );
        toWikiTooltips.beginUpdateTooltip( id, html, !toWikiTooltips.config.preloadMissingPageTooltip );
      } else if ( tooltipData.toEmptyPageName && toWikiTooltips.config.emptyPageNameTooltip !== null ) {
        var html = toWikiTooltips.config.emptyPageNameTooltip.replace( /\$1/g, tooltipData.toTargetTitle );
        toWikiTooltips.beginUpdateTooltip( id, html, !toWikiTooltips.config.preloadEmptyPageNameTooltip );
      } else {
        toWikiTooltips.removeTooltip( link, id );
      }
    } else {
      toWikiTooltips.removeTooltip( link, id );
    }
  },
  
  /**
   * Sets the content of the indicated tooltip box to that of the loading tooltip and shows it.
   * @param {Element} tooltipBox The tooltip div.
   * @param {string} id The unique id fragment of the tooltip div.
   */
  setToLoadingTooltip : function ( tooltipBox, id ) {
    var tooltipData = tooltipBox.data( );
    tooltipData.toShowWhenLoaded = true;
    if ( toWikiTooltips.config.loadingTooltip !== null ) {
      tooltipData.toIsImage = ( toWikiTooltips.config.preloadLoadingTooltip ? 'true' : 'false' );
      if ( 'toTargetTitle' in tooltipData ) {
        tooltipBox.html( toWikiTooltips.config.loadingTooltip.replace( /\$1/g, tooltipData.toTargetTitle ) );
      } else {
        tooltipBox.html( toWikiTooltips.config.loadingTooltip.replace( /\$1/g, '' ) );
      }
      toWikiTooltips.resizeTooltip( id );
      toWikiTooltips.positionTooltip( id );
      toWikiTooltips.visibleTooltipId = id;
      toWikiTooltips.updateTooltipVisibility();
    }
  },

  /**
   * If the tooltip is already loaded or in the process of loading, this simply displays the tooltip. If not, it
   * begins the loading process, and depending on configuration, may immediately show a loading tooltip. This is
   * usually called on mouseover events on links with enabled tooltips.
   */
  showTooltip : function( ) {
    console.log( "showTooltip" );
    var linkData = $( this ).data( );
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + linkData.toId );
    if ( tooltipBox.length > 0 ) {
      var tooltipData = tooltipBox.data( );

      if ( tooltipData.toLoaded ) {
        toWikiTooltips.visibleTooltipId = linkData.toId;
        toWikiTooltips.updateTooltipVisibility();
      } else if ( tooltipData.toLoading && toWikiTooltips.config.loadingTooltip !== null ) {
        toWikiTooltips.visibleTooltipId = linkData.toId;
        toWikiTooltips.updateTooltipVisibility();
      } else if ( !tooltipData.toLoading && !tooltipData.toChecking ) {
        if ( toWikiTooltips.config.loadingTooltip !== null ) {
          if ( toWikiTooltips.config.useTwoRequestProcess ) {
            tooltipData.toChecking = true;
            toWikiTooltips.beginCheck( this, linkData.toId, linkData.toTargetTitle, linkData.toTooltipTitle );
          } else {
            tooltipData.toLoading = true;
            toWikiTooltips.setToLoadingTooltip( tooltipBox, linkData.toId );
            toWikiTooltips.beginLoadTooltip( this, linkData.toId, linkData.toTargetTitle, linkData.toTooltipTitle );
          }
        } else {
          tooltipData.toLoading = true;
          tooltipData.toShowWhenLoaded = true;
          toWikiTooltips.beginLoadTooltip( this, linkData.toId, linkData.toTargetTitle, linkData.toTooltipTitle );
        }
      }
    } else {
      toWikiTooltips.removeTooltip( this, linkData.toId );
    }
  },

  /**
   * Resets a tooltip div to its initial state.
   * @param {string} id The unique id fragment for the tooltip box.
   */
  resetTooltip : function( id ) {
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    var data = tooltipBox.data();
    data.toChecking = false;
    data.toLoaded = false;
    data.toLoading = false;
    data.toShowWhenLoaded = false;
    tooltipBox.html( '' );
    tooltipBox.hide();
    toWikiTooltips.updateTooltipVisibility();
  },

  /**
   * Event handler for the mousemove events on tooltips.
   * @param {object} event The event object, containing the unique id fragment 
   */
  moveTooltip : function( event ) {
    toWikiTooltips.pageX = event.pageX;
    toWikiTooltips.pageY = event.pageY;
    var srcData = $( this ).data();
    if ( 'toId' in srcData ) {
      toWikiTooltips.positionTooltip( srcData.toId );
    } else {
      toWikiTooltips.positionTooltip( $( this ).attr( 'id' ) );
    }
  },

  /**
   * Performs repositioning of the indicated tooltip box.
   * @param {string} id The unique id fragment for the tooltip box.
   */
  positionTooltip: function( id ) {
    var tooltip = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    var bodyOffsets = document.body.getBoundingClientRect();
    var bodyX = toWikiTooltips.pageX - bodyOffsets.left;
    var bodyY = toWikiTooltips.pageY - bodyOffsets.top;
    var scrollX = $( document ).scrollLeft();
    var scrollY = $( document ).scrollTop();
    var bodyWidth = $( 'body' ).width();
    var bodyHeight = $( 'body' ).height();
    var viewWidth = $( window ).width();
    var viewHeight = $( window ).height();
    var topAdjust = 6;

    if ( tooltip.width() * 1.1 > toWikiTooltips.pageX - scrollX ) {
      tooltip.css( { 'left' : bodyX - scrollX + 15, 'right' : 'auto' } );
      topAdjust = 36;
    } else {
      tooltip.css( { 'right' : bodyWidth - bodyX + scrollX + 6, 'left' : 'auto' } );
    }

    if ( tooltip.height() + topAdjust > toWikiTooltips.pageY - scrollY ) {
      tooltip.css( { 'top' : bodyY - scrollY + topAdjust, 'bottom' : 'auto' } );
    } else {
      tooltip.css( { 'bottom' : bodyHeight - bodyY + scrollY + 3, 'top' : 'auto' } );
    }
  },
  
  /**
   * Performs resizing of the currently visible tooltip if it is an image tooltip.
   */
  resizeVisibleTooltip : function( ) {
    if ( toWikiTooltips.visibleTooltipId !== null ) {
      toWikiTooltips.resizeTooltip( toWikiTooltips.visibleTooltipId );
    }
  },
  
  /**
   * Performs resizing of single-image tooltips.
   * @param {string} id The unique id fragment for the tooltip box.
   */
  resizeTooltip : function( id ) {
    toWikiTooltips.tooltipHeight = Math.round( $( window ).height() / 2 );
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + id );
    if ( tooltipBox.data( 'toIsImage' ) ) {
      var images = tooltipBox.find( 'img' );
      if ( images.length > 0 ) {
        var image = images[0];
        var imageCopy = new Image();
        imageCopy.src = image.src;
        $( image ).css( 'height', Math.min( toWikiTooltips.tooltipHeight, imageCopy.height ) + "px" );
        $( image ).css( 'width', 'auto' );
      }
    }
  },

  /**
   * Handles the mouseout event for tooltip-enabled links by hiding the associated tooltip box.
   * @returns {undefined}
   */
  hideTooltip : function( ) {
    var linkData = $( this ).data( );
    var tooltipBox = $( '#' + toWikiTooltips.tooltipClassPrefix + linkData.toId );
    var tooltipData = tooltipBox.data( );
    
    tooltipData.toShowWhenLoaded = false;
    if ( toWikiTooltips.visibleTooltipId === linkData.toId ) {
      toWikiTooltips.visibleTooltipId = null;
    }
    toWikiTooltips.updateTooltipVisibility();
  },
  
  /**
   * Loops through all tooltips, hiding all but the one that is supposed to be visible, and shows that one if it is
   * not already visible.
   */
  updateTooltipVisibility : function( ) {
    $( '.to_tooltip' ).each( function() {
      if ( toWikiTooltips.visibleTooltipId !== null &&
           $( this ).attr( 'id' ) === ( toWikiTooltips.tooltipClassPrefix + toWikiTooltips.visibleTooltipId ) 
         ) {
        $( this ).show();
      } else {
        $( this ).hide();
      }
    } );
  }
};

$( document ).ready( toWikiTooltips.beginInitialize );