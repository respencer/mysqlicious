<?php

// MySQLicious 1.0
// A del.icio.us to MySQL mirroring tool.
// Copyright 2005 Adam Nolley - http://nanovivid.com

class MySQLicious {
	
	// store update dates here
	var $MySQLiciousDataTable;
	
	// container vars for passing stuff in and out of the XML parser functions
	var $remoteUpdate;
	var $localPosts; 
	var $foundPosts;
	var $mirrorStats;
	
	// MySQL stuff
	var $mysqlLink;
	var $mysqlTable;
	
	// can be used to force an update regardless of what del.icio.us says
	var $forceUpdate;
	
	// http://del.icio.us/api ... or whatever it needs to be
	var $deliciousAPIPrefix;
	
	// =============================================================================================================
	//                                               MySQLicious
	// =============================================================================================================
	
	// -------------------------------------------------------------------------------------------------------------
	// MySQLicious constructor
	// Parameters:
	//  $mysqlHost			address of your MySQL server - usually "localhost"
	//  $mysqlDatabase		MySQL database in which your del.icio.us bookmarks will be stored
	//  $mysqlUsername		username for MySQL
	//  $mysqlPassword		password for MySQL
	function MySQLicious($mysqlHost, $mysqlDatabase, $mysqlUsername, $msyqlPassword) {
		// set up some defaults
		$this->remoteUpdate = "";
		$this->localPosts = array(); 
		$this->foundPosts = array();
		$this->mirrorStats = array();
		
		// set the del.icio.us API
		$this->setAPIAddress("http://del.icio.us/api/");
		
		// data table for storing update dates
		$this->MySQLiciousDataTable = "MySQLicious";
		
		// by default, we don't want to force an update
		$this->forceUpdate = false;
		
		// connect to the MySQL server and select the database
		$this->mysqlLink = mysql_connect($mysqlHost, $mysqlUsername, $msyqlPassword) or die("Could not connect to MySQL server.");
		$db = mysql_select_db($mysqlDatabase, $this->mysqlLink) or die("Could not select specified database ($mysqlDatabase).");
		
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mirror - kick off mirroring of a del.icio.us account
	// Parameters:
	//  $deliciousUsername	username for del.icio.us
	//  $deliciousPassword	password for del.icio.us
	//  $mysqlTable			MySQL table into which the del.icio.us bookmarks should be mirrored. 
	//						NOTE: It will be created auotmatically if it doesn't exist.
	//  $deliciousTag		[optional] Tag to filter by. If not specified, all bookmarks will be mirrored.
	function mirror($deliciousUsername, $deliciousPassword, $mysqlTable, $deliciousTag = "") {
		$this->mysqlTable = $mysqlTable;
		
		// make sure that the necessary MySQL tables exist
		$this->mysqlCheckTables();

		// try find out the last time we updated
		// if we can't find the value, $localUpdate will be set to false
		$localUpdate = $this->mysqlGetLocalUpdate($deliciousTag);
		
		// find out the last time del.icio.us was updated
		$this->deliciousAPI("posts/update", $deliciousUsername, $deliciousPassword);
		
		// let's do some updating!
		if (($this->remoteUpdate > $localUpdate) or !$localUpdate or $this->forceUpdate) {
			echo "Update may be needed. Checking now.\n\n";
			
			// gather all posts from the MySQL mirror for comparison to the del.icio.us ones
			$sql = "SELECT * FROM `".$this->mysqlTable."`";
			$result = mysql_query($sql);
			if ($result and mysql_num_rows($result) > 0) {
				while ($row = mysql_fetch_assoc($result)) {
					$this->localPosts[$row['hash']]['description'] = $row['description'];
					$this->localPosts[$row['hash']]['extended'] = $row['extended'];
					$this->localPosts[$row['hash']]['tags'] = $row['tags'];
					$this->localPosts[$row['hash']]['date'] = $row['date'];
				}
			}
			
			// tracking vars for stats
			$this->mirrorStats['numInserted'] = 0;
			$this->mirrorStats['numUpdated'] = 0;
			$this->mirrorStats['numDeleted'] = 0;
			
			// grab all posts from del.icio.us
			$APIParam = (strlen($deliciousTag) > 0) ? "?tag=$deliciousTag" : "";
			$this->deliciousAPI("/posts/all$APIParam", $deliciousUsername, $deliciousPassword);
			
			// if there are any posts that exist in MySQL but not on del.icio.us, get rid of the local copies
			$deletePosts = array_diff(array_keys($this->localPosts), array_keys($this->foundPosts));
			if ( count($deletePosts) > 0 ) {
				foreach ( $deletePosts as $deleteMe ) {
					$this->mysqlDeliciousDelete($deleteMe);
				}
			}
			
			// we've updated, so let's record that
			$this->mysqlSetLocalUpdate($deliciousTag);
			
			// display stats if there are any
			if (($this->mirrorStats['numInserted'] + $this->mirrorStats['numUpdated'] + $this->mirrorStats['numDeleted']) > 0) {
				if ($this->mirrorStats['numInserted'] > 0) {
					echo "\n" . $this->mirrorStats['numInserted'] . " inserted.";
				}
				if ($this->mirrorStats['numUpdated'] > 0) {
					echo "\n" . $this->mirrorStats['numUpdated'] . " updated.";
				}
				if ($this->mirrorStats['numDeleted'] > 0) {
					echo "\n" . $this->mirrorStats['numDeleted'] . " deleted.";
				}
			} else {
				echo "No update needed.";
			}
			
		} else {
			echo "No update needed.";
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// xml_start_element - parse the stuff handed back by the del.icio.us api
	// see http://www.php.net/xml-set-element-handler for why how this relates to XML parsing
	function xml_start_element($parser, $name, $attrs) {
		
		switch ($name) {
			
			// if we are parsing /posts/update, we'll hit an <update time=""> tag
			// grab that value and record it
			case "update":
				$this->remoteUpdate = $this->time_deliciousToTimestamp($attrs['time']);
				break;
				
			// if we are parsing /posts/all, we'll hit <post ...> tags
			case "post":
				
				// if the post doesn't exist in MySQL, insert it
				if (!array_key_exists($attrs['hash'], $this->localPosts)) {
					$this->mysqlDeliciousInsert($attrs);
				
				// if some part of the post in MySQL doesn't match the del.icio.us version, update it
				} elseif ($this->localPosts[$attrs['hash']]['description'] != $attrs['description'] or
						  $this->localPosts[$attrs['hash']]['extended'] != $attrs['extended'] or
						  $this->localPosts[$attrs['hash']]['tags'] != $attrs['tag'] ) {
					$this->mysqlDeliciousUpdate($attrs);
				}
				
				// record the fact that we've found this post -- otherwise it'll be deleted
				$this->foundPosts[$attrs['hash']] = true;
				
				break;
		}
	}
	// -------------------------------------------------------------------------------------------------------------
	// we don't need to do anything when we hit the end of an XML element, but this function has to exist
	function xml_end_element($parser, $name) {}
	
	// -------------------------------------------------------------------------------------------------------------
	// convertDeliciousTagForMysql - When recording the last update time, use the value set by this function if
	//                               there is no tag filter set.
	// Paramenters:
	//  $tag		tag set by user
	function convertDeliciousTagForMysql($tag) {
		return (strlen($tag) > 0) ? $tag : "MySQLiciousWithNoTagFilter";
	}
	
	
	// =============================================================================================================
	//                                       del.icio.us API functions
	// =============================================================================================================
	
	
	// -------------------------------------------------------------------------------------------------------------
	// setAPIAddress - change the API address
	// Parameters:
	//  $delAPI		http://del.icio.us/apiaddress
	function setAPIAddress($delAPI) {
		if ($delAPI{strlen($delAPI) - 1} != "/") {
			$delAPI .= "/";
		}
		$this->deliciousAPIPrefix = $delAPI;
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// deliciousAPI - make an API call
	// Parameters:
	//  $apiCall		call being made, in the format of "api/call" - note that there is no slash at the beginning
	//  $user			del.icio.us username
	//	$pass			del.icio.us password
	function deliciousAPI($apiCall, $user, $pass) {
		// we need to recreate the XML parser every time because there's a (silly) PHP bug where you can't reuse a parser.
		$p = $this->createXMLParser();
		
		// parse the del.icio.us content returned from the API
		// in the future, some checking should probably be added here in case of errors or bad content
		if (!xml_parse($p, $this->deliciousDoAPI($apiCall, $user, $pass), true)) {
			echo xml_error_string(xml_get_error_code($p)) . " at line " . xml_get_current_line_number($p) . "<br>\n";
		}
		
		xml_parser_free($p);
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// deliciousDoAPI - just a wrapper to make API actions a little prettier
	// Parameters:
	//  $apiCall		call being made, in the format of "api/call" - note that there is no slash at the beginning
	//  $user			del.icio.us username
	//	$pass			del.icio.us password
	function deliciousDoAPI($apiCall, $user, $pass) {
		return $this->curlIt($this->deliciousAPIPrefix.$apiCall, $user, $pass);
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// curlIt - grab a URL with CURL
	// Parameters:
	//  $url		full URL to fetch
	//  $user		del.icio.us username
	//	$pass		del.icio.us password
	function curlIt($url, $user, $pass) {
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $url);				// this is the page we're grabbing
		curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");	// HTTP auth username/password
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);		// we want the data passed back
		curl_setopt($ch, CURLOPT_USERAGENT, "MySQLicious");	// set our useragent because del.icio.us likes it that way
		
		$page = curl_exec($ch);
		
		// if we get throttled, let's wait for a while
		if ( curl_getinfo($ch, CURLINFO_HTTP_CODE) == 503 ) {
			echo "WARNING: You are being throttled by the del.icio.us server. Pausing execution for 10 seconds.";
			sleep(10);
		}
		
		curl_close($ch);
		
		return trim($page);
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// createXMLParser - since we have to create a new XML parser for each API call, let's wrap it to make it pretty
	function createXMLParser() {
		$p = xml_parser_create();
		xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($p, XML_OPTION_SKIP_WHITE, 1);
		xml_set_element_handler($p, array(&$this, "xml_start_element"), array(&$this, "xml_end_element"));
		return $p;
	}

	
	// =============================================================================================================
	//                                       MySQL Database Functions
	// =============================================================================================================
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlGetLocalUpdate - make sure our data tables exist. if not, create them.
	function mysqlCheckTables() {
		// make sure the MySQLicious data table exists
		if (!$this->mysqlTableExists($this->MySQLiciousDataTable)) {
			$sql  = "CREATE TABLE `".$this->MySQLiciousDataTable."` (`tag` varchar(255) NOT NULL default '', ";
			$sql .= "`lastupdate` datetime default NULL, PRIMARY KEY (`tag`)) TYPE=MyISAM";
			$result = mysql_query($sql, $this->mysqlLink) or die("Unable to create table to store MySQLicious data.");
		}

		// make sure the table we're going to stick posts in exists
		if (!$this->mysqlTableExists($this->mysqlTable)) {
			$sql  = "CREATE TABLE `".$this->mysqlTable."` (`id` int(11) NOT NULL auto_increment, `url` text, `description` text, ";
			$sql .= "`extended` text, `tags` text, `date` datetime default NULL, `hash` varchar(255) default NULL, ";
			$sql .= "PRIMARY KEY  (`id`), KEY `date` (`date`)) TYPE=MyISAM";
			$result = mysql_query($sql, $this->mysqlLink) or die("Unable to create ".$this->mysqlTable." to store del.icio.us posts.");
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlGetLocalUpdate - find out the last time an update was done for the given tag
	// Parameters:
	//  $tag		tag to check on
	function mysqlGetLocalUpdate($tag) {
		$sql = "SELECT `lastupdate` FROM `".$this->MySQLiciousDataTable."` WHERE `tag` = \"".$this->convertDeliciousTagForMysql($tag)."\" LIMIT 1";
		
		$result = mysql_query($sql, $this->mysqlLink) or die("\n" . mysql_error($this->mysqlLink) . "\n$sql\n");
			
		if (mysql_num_rows($result) == 1) {
			$row = mysql_fetch_assoc($result);
			return strtotime($row['lastupdate']);
		} else {
			// if no result, we've never updated and need to
			return false;
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlSetLocalUpdate - record that we've done an update
	// Parameters:
	//  $tag		tag to set update time for
	function mysqlSetLocalUpdate($tag) {
		$tag = $this->convertDeliciousTagForMysql($tag);
		$remoteUpdate = $this->time_timestampToMysql($this->remoteUpdate);
		
		if ($this->mysqlGetLocalUpdate($tag) != false) {
			// already exists, need to update
			$sql = "UPDATE `".$this->MySQLiciousDataTable."` SET `lastupdate` = \"$remoteUpdate\" WHERE `tag` = \"$tag\"";
		} else {
			// first time, need to insert
			$sql = "INSERT INTO `".$this->MySQLiciousDataTable."` (`tag`, `lastupdate`) VALUES (\"$tag\", \"$remoteUpdate\")";
		}
		$result = mysql_query($sql, $this->mysqlLink) or die("\n" . mysql_error($this->mysqlLink) . "\n$sql\n");
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousUpdate - update the local copy of a post
	// Parameters:
	//  $attrs		attributes from XML <post ...> element
	function mysqlDeliciousUpdate($attrs) {
		$sql  = "UPDATE `".$this->mysqlTable."` SET ";
		$sql .= "`description` = '" . $this->escapeSingleQuotes($attrs['description']) . "', ";
		$sql .= "`extended` = '" . $this->escapeSingleQuotes($attrs['extended']) . "', ";
		$sql .= "`tags` = '{$attrs['tag']}' ";
		// uncomment the next line to change the item's date (i've had issues where del.icio.us changes the item date when it shouldn't so i disabled this)
		// $sql .= "`date` = '" . $this->time_deliciousToMysql($attrs['time']) . "' ";
		$sql .= "WHERE `hash` = '{$attrs['hash']}'";
		
		$result = mysql_query($sql, $this->mysqlLink);
		if ( !$result ) {
			echo "\n" . mysql_error($this->mysqlLink);
			echo "\n$sql\n";
		} else {
			echo "Updated {$attrs['href']}\n";
			$this->mirrorStats['numUpdated'] ++;
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousInsert - insert a post from del.icio.us into MySQL
	// Parameters:
	//  $attrs		attributes from XML <post ...> element
	function mysqlDeliciousInsert($attrs) {
		$sql  = "INSERT INTO `".$this->mysqlTable."` (`hash`, `url`, `description`, `extended`, `tags`, `date`) ";
		$sql .= "VALUES ('{$attrs['hash']}', '{$attrs['href']}', '" . $this->escapeSingleQuotes($attrs['description']) . "', '" . $this->escapeSingleQuotes($attrs['extended']) . "', ";
		$sql .= "'{$attrs['tag']}', '" . $this->time_deliciousToMysql($attrs['time']) . "')";
		
		$result = mysql_query($sql, $this->mysqlLink);
		if ( !$result ) {
			echo "\n" . mysql_error($this->mysqlLink);
			echo "\n$sql\n";
		} else {
			echo "Inserted {$attrs['href']}\n";
			$this->mirrorStats['numInserted'] ++;
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousDelete - remove a post from MySQL
	// Parameters:
	//  $hash		MD5 hash of the URL to be removed
	function mysqlDeliciousDelete($hash) {
		$sql = "DELETE FROM `".$this->mysqlTable."` WHERE `hash` = '$hash' LIMIT 1";
		
		$result = mysql_query($sql, $this->mysqlLink);
		if ( !$result ) {
			echo "\n" . mysql_error($this->mysqlLink);
			echo "\n$sql\n";
		} else {
			echo "Deleted $hash\n";
			$this->mirrorStats['numDeleted'] ++;
		}
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// mysqlTableExists - check to see if $table exists
	function mysqlTableExists($table) {
		$exists = mysql_query("SELECT 1 FROM `$table` LIMIT 0", $this->mysqlLink);
		return ($exists) ? true : false;
	}
	
	
	// =============================================================================================================
	//                                       time and text conversion functions
	// =============================================================================================================
	
	// -------------------------------------------------------------------------------------------------------------
	// time_deliciousToTimestamp - convert $time given by del.icio.us API call to a UNIX timestamp
	function time_deliciousToTimestamp($time) {
		return strtotime(ereg_replace('T|Z',' ', $time));
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// time_deliciousToMysql - convert $time given by del.icio.us API call to MySQL friendly datetime
	function time_deliciousToMysql($time) {
		return $this->time_timestampToMysql($this->time_deliciousToTimestamp($time));
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// time_timestampToMysql - convert $time UNIX time to MySQL friendly datetime
	function time_timestampToMysql($timestamp) {
		return date("Y-m-d H:i:s", $timestamp);
	}
	
	// -------------------------------------------------------------------------------------------------------------
	// escapeSingleQuotes - convert ' to \'
	function escapeSingleQuotes($str) {
		return str_replace("'", "\'", $str);
	}
}


?>