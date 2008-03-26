<?php
/* $Id$
 *
 * Description:
 * This script is intended to be used outside of normal WebCalendar
 * use,  as an RSS 2.0 feed to a RSS client.
 *
 * You must have "Enable RSS feed" set to "Yes" in both System
 * Settings and in the specific user's Preferences.
 *
 * Simply use the URL of this file as the feed address in the client.
 * For public user access:
 * http://xxxxx/aaa/rss.php
 * For any other user (where "joe" is the user login):
 * http:/xxxxxx/aaa/rss.php?user=joe
 *
 * By default (if you do not edit this file), events
 * will be loaded for either:
 *   - the next 30 days
 *   - the next 10 events
 *
 * Input parameters:
 * You can override settings by changing the URL parameters:
 *   - days: number of days ahead to look for events
 *   - cat_id: specify a category id to filter on
 *   - repeats: output all events including all repeat instances
 *       repeats=0 do not output repeating events (default)
 *       repeats=1 outputs repeating events 
 *       repeats=2 outputs repeating events but suppresses display of
 *                 2nd & subsequent occurences of daily events
 *   - user: login name of calendar to display (instead of public user).
 *       You must have the following System Settings configured for this:
 *         Allow viewing other user's calendars: Yes
 *         Public access can view others: Yes
 *   - showdate: put the date and time (if specified in the title 
 *       of the item) in the title
 *
 * Security:
 * _ENABLE_RSS must be set true
 * ENABLE_USER_RSS must be set true unless this is for the public user
 * USER_REMOTE_ACCESS can be set as follows in pref.php
 *      0 = Public entries only
 *      1 = Public & Confidential entries only
 *      2 = All entries are included in the feed *USE WITH CARE
 *   
 * We do not include unapproved events in the RSS feed.
 *
 *
 * TODO
 * Add other RSS 2.0 options such as media
 * Add <managingEditor>: dan@spam_me.com (Dan Deletekey)
 */

$debug=FALSE;

 require_once 'includes/classes/WebCalendar.class.php';
 require_once 'includes/classes/Event.class.php';
 require_once 'includes/classes/RptEvent.class.php';
     
 $WC =& new WebCalendar ( __FILE__ );    
     
 include 'includes/functions.php';    
 include 'includes/config.php';    
 include 'includes/dbi4php.php';    
     
 $WC->initializeFirstPhase();    
     
 include 'includes/translate.php';    
 include 'includes/site_extras.php';
 
include_once 'includes/xcal.php';

 $WC->initializeSecondPhase();


$WC->setLanguage();


if ( ! getPref ( '_ENABLE_RSS' ) ) {
  header ( 'Content-Type: text/plain' );
  echo print_not_auth ();
  exit;
}
/*
 *
 * Configurable settings for this file.  You may change the settings
 * below to change the default settings.
 * These settings will likely move into the System Settings in the
 * web admin interface in a future release.
 *
 */

//Show the date in the title and how to format it
$date_in_title = false;  //can overriden with "rss.php?showdate=1|true"
$showdate = $WC->getValue ( 'showdate' );
if ( ! empty ( $showdate ) ) {
  $date_in_title = ( $showdate == 'true' || $showdate == 1 ? true : false );
}
$date_format = 'M jS';  //Aug 10th, 8/10
$time_format = 'g:ia';  //4:30pm, 16:30
$time_separator = ', '; //Aug 10th @ 4:30pm, Aug 10th, 4:30pm

// Default time window of events to load
// Can override with "rss.php?days=60"
$numDays = 30;

// Max number of events to display
// Can override with "rss.php?max=20"
$maxEvents = 10;

// Login of calendar user to use
$userID = getPref ( '_DEFAULT_RSS_USER' );

// Load layers
$load_layers = false;

// Load just a specified category (by its id)
// Leave blank to not filter on category (unless specified in URL)
// Can override in URL with "rss.php?cat_id=4"
$cat_id = '';

// Load all repeating events
// Can override with "rss.php?repeats=1"
$allow_repeats = false;

// Load show only first occurence within the given time span of daily repeating events
// Can override with "rss.php?repeats=2"
$show_daily_events_only_once = false;

// End configurable settings...

if ( get_Pref ( '_ALLOW_USER_OVERRIDE', 2 ) ) {
  $u = $WC->getValue ( 'user', "[0-9]+", true );
  if ( ! empty ( $u ) ) {
    $userID = $u;
    // We also set $login since some functions assume that it is set.
  }
}
$WC->_login = $userID;

// Determine what remote access has been set up by user
if ( ! empty ( $USER_REMOTE_ACCESS ) ) {
  if ( $USER_REMOTE_ACCESS == 1 ) { //public or confidential
    $allow_access = array('P', 'C');
  } else if ( $USER_REMOTE_ACCESS == 2 ){ //all entries included
    $allow_access = array('P', 'C', 'R');
  } 
} else { 
  $allow_access = array('P');
}

$WC->User->loadVariables ( $WC->loginId(), 'rss_' );
$creator = $rss_fullname;

if ( ! getPref ( 'ENABLE_USER_RSS' ) )  {
  header ( 'Content-Type: text/plain' );
  echo print_not_auth ();
  exit;
}

if ( $WC->catId() ) {
    $category = $categories[$WC->catId()]['cat_name'];

}

if ( $load_layers ) {
  $layers = loadLayers ( $userID );
}


// Calculate date range
$date = $WC->getValue ( 'date', '-?[0-9]+', true );
if ( empty ( $date ) || strlen ( $date ) != 8 ) {
  // If no date specified, start with today
  $date = date ( 'Ymd' );
}
$thisyear = substr ( $date, 0, 4 );
$thismonth = substr ( $date, 4, 2 );
$thisday = substr ( $date, 6, 2 );

$startTime = mktime ( 0, 0, 0, $thismonth, $thisday, $thisyear );

$x = $WC->getValue ( 'days', '-?[0-9]+', true );
if ( ! empty ( $x ) ) {
  $numDays = $x;
}
// Don't let a malicious user specify more than 365 days
if ( $numDays > 365 ) {
  $numDays = 365;
}
$x = $WC->getValue ( 'max', '-?[0-9]+', true );
if ( ! empty ( $x ) ) {
  $maxEvents = $x;
}
// Don't let a malicious user specify more than 100 events
if ( $maxEvents > 100 ) {
  $maxEvents = 100;
}

$x = $WC->getValue ( 'repeats', '-?[0-9]+', true );
if ( ! empty ( $x ) ) {
  $allow_repeats = $x;
  if ( $x==2 ) {
    $show_daily_events_only_once = true;
  }
}

$endTime = mktime ( 0, 0, 0, $thismonth, $thisday + $numDays -1,
  $thisyear );
$endDate = date ( 'Ymd', $endTime );


/* Pre-Load the repeated events for quicker access */
if (  $allow_repeats == true )
  $repeated_events = read_repeated_events ( $userID, $startTime, $endTime, $cat_id );

/* Pre-load the non-repeating events for quicker access */
$events = read_events ( $userID, $startTime, $endTime, $cat_id );

$language = getPref ( 'LANGUAGE' );
$charset = ( $language ? translate( 'charset' ): 'iso-8859-1' );
// This should work ok with RSS, may need to hardcode fallback value
$lang = languageToAbbrev ( $language );
if ( $lang == 'en' ) $lang = 'en-us'; //the RSS 2.0 default

$appStr =  generate_application_name ();
$server_url = getPref ( 'SERVER_URL', 2 );
//header('Content-type: application/rss+xml');
header('Content-type: text/xml');
echo '<?xml version="1.0" encoding="' . $charset . '"?>';
?>
<rss version="2.0" xml:lang="<?php echo $lang ?>">
 
<channel>
<title><![CDATA[<?php echo $appStr ?>]]></title>
<link><?php echo $server_url; ?></link>
<description><![CDATA[<?php echo $appStr ?>]]></description>
<language><?php echo $lang; ?></language>
<generator>:"http://www.k5n.us/webcalendar.php?v=<?php 
echo _WEBCAL_PROGRAM_VERSION; ?>"</generator>
<image>
<title><![CDATA[<?php echo $appStr ?>]]></title>
<link><?php echo $server_url; ?></link>
<url>http://www.k5n.us/k5n_small.gif</url>
</image>
<?php
$numEvents = 0;
$reventIds = array();
$endtimeYmd = date ( 'Ymd', $endTime );
for ( $i = $startTime; date ( 'Ymd', $i ) <= $endtimeYmd &&
  $numEvents < $maxEvents; $i += ONE_DAY ) {
  $eventIds=array();
  $d = date ( 'Ymd', $i );
  $pubDate = gmdate ( 'D, d M Y', $i );
  $entries = get_entries ( $d, false  );
  $rentries = get_repeating_entries ( $userID, $d );
  $entrycnt = count ( $entries );
  $rentrycnt = count ( $rentries );
  if ($debug) echo "\n\ncountentries==". $entrycnt . " " . $rentrycnt . "\n\n";
  if ( $entrycnt > 0 || $rentrycnt > 0 ) {
    for ( $j = 0; $j < $entrycnt && $numEvents < $maxEvents; $j++ ) {
      // Prevent non-Public events from feeding
      if ( in_array ( $entries[$j]->getAccess(), $allow_access ) ) {
        $eventIds[] = $entries[$j]->getId();
        $unixtime = date_to_epoch ( $entries[$j]->getDateTime() );
        if ( $date_in_title ) {
          $itemtime = ( $entries[$j]->isAllDay() || $entries[$j]->isUntimed() ?
            $time_separator . date ( $time_format, $unixtime ): '' ) . ' ';
          $dateinfo = date ( $date_format, $unixtime ) . $itemtime;
        } else {
          $dateinfo = '';
        }
        echo "\n<item>\n";
        echo '<title><![CDATA[' . $dateinfo .  
          $entries[$j]->getName() . "]]></title>\n";
        echo '<link>' . $server_url . 'view_entry.php?eid=' . 
          $entries[$j]->getId() . '&amp;friendly=1&amp;rssuser=' 
		  . $WC->loginId() . '&amp;date=' . $d . "</link>\n";
        echo "<description><![CDATA[" .
          $entries[$j]->getDescription() . "]]></description>\n";
        if ( ! empty ( $category ) )
          echo "<category><![CDATA[" . $category . "]]></category>\n";
        //echo '<creator><![CDATA[' . $creator . "]]></creator>\n";
        //RSS 2.0 date format Wed, 02 Oct 2002 13:00:00 GMT
        echo '<pubDate>' . gmdate ( 'D, d M Y H:i:s', $unixtime ) ." GMT</pubDate>\n";
        echo '<guid>' . $server_url . 'view_entry.php?eid=' . 
          $entries[$j]->getId() . '&amp;friendly=1&amp;rssuser='
		  . $WC->loginId() . '&amp;date=' . $d . "</guid>\n";
        echo "</item>\n";
        $numEvents++;
      }
    }
    for ( $j = 0; $j < $rentrycnt && $numEvents < $maxEvents; $j++ ) {

          //to allow repeated daily entries to be suppressed
          //step below is necessary because 1st occurence of repeating 
          //events shows up in $entries AND $rentries & we suppress display
          //of it in $rentries
       if ( in_array($rentries[$j]->getId(),$eventIds)  && 
             $rentries[$j]->getrepeatType()== 'daily' ) {
               $reventIds[]=$rentries[$j]->getId(); 
          }


      // Prevent non-Public events from feeding
      // Prevent a repeating event from displaying if the original event 
      // has already been displayed; prevent 2nd & later recurrence
      // of daily events from displaying if that option has been selected
      if ( ! in_array($rentries[$j]->getId(),$eventIds ) && 
         ( ! $show_daily_events_only_once || ! in_array($rentries[$j]->getId(),$reventIds )) && 
         ( in_array ( $rentries[$j]->getAccess(), $allow_access ) ) ) { 
  
          //show repeating events only once
          if ( $rentries[$j]->getrepeatType()== 'daily' ) 
                  $reventIds[]=$rentries[$j]->getId(); 


        echo "\n<item>\n";
        $unixtime = date_to_epoch ( $rentries[$j]->getDateTime() );
        if ( $date_in_title == true ) {
          $itemtime = ( $rentries[$j]->isAllDay() || $rentries[$j]->isUntimed() ?
            $time_separator . date ( $time_format, $unixtime ): '' ) . ' ';
          $dateinfo = date ( $date_format, $i ) . $itemtime;
        } else {
          $dateinfo = '';
        }
        echo '<title><![CDATA[' . $dateinfo .    
          $rentries[$j]->getName() . "]]></title>\n";
        echo '<link>' . $server_url . "view_entry.php?eid=" . 
          $rentries[$j]->getId() . '&amp;friendly=1&amp;rssuser='
		  . $WC->loginId() . '&amp;date=' . $d . "</link>\n";
        echo "<description><![CDATA[" .
          $rentries[$j]->getDescription() . "]]></description>\n";
        if ( ! empty ( $category ) )
          echo "<category><![CDATA[" . $category . "]]></category>\n";
       // echo '<creator><![CDATA[' . $creator . "]]></creator>\n";
        echo '<pubDate>' . $pubDate . ' ' . gmdate ( 'H:i:s', $unixtime ) 
         . " GMT</pubDate>\n";
        echo '<guid>' . $server_url . 'view_entry.php?eid=' . 
          $rentries[$j]->getId() . '&amp;friendly=1&amp;rssuser='
		  . $WC->loginId() . '&amp;date=' . $d . "</guid>\n";
        echo "</item>\n";   
        $numEvents++;
      }
    }
  }
}
echo "</channel></rss>\n";
exit;

?>
