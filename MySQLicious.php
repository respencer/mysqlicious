<?php

// MySQLicious 1.2
// A Delicious to MySQL mirroring tool.
//
// For documentation, see https://github.com/respencer/mysqlicious/wiki
//
//
// Copyright (c) 2014, Robert E. Spencer
// Copyright (c) 2005-2008, Adam Nolley (nanovivid)
// All rights reserved.
//
// Redistribution and use in source and binary forms, with or without
// modification, are permitted provided that the following conditions are met:
//
//  * Redistributions of source code must retain the above copyright notice,
//    this list of conditions and the following disclaimer.
//
//  * Redistributions in binary form must reproduce the above copyright notice,
//    this list of conditions and the following disclaimer in the documentation
//    and/or other materials provided with the distribution.
//
//  * Neither the name Adam Nolley (nanovivid), nor the names of its
//    contributors may be used to endorse or promote products derived from
//    this software without specific prior written permission.
//
// THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDER AND CONTRIBUTORS "AS IS"
// AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
// IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
// ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
// LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
// CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
// SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
// INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
// CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
// ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
// POSSIBILITY OF SUCH DAMAGE.

define("MYSQLICIOUS_OUTPUT_HTML", "html");
define("MYSQLICIOUS_OUTPUT_CMD", "cmd");
define("MYSQLICIOUS_OUTPUT_NONE", "none");

class MySQLicious {
	// Store update dates here.
	var $MySQLiciousDataTable;

	// Output.
	var $out;

	// Storage for errors.
	var $isError;
	var $errorText;

	// Container vars for passing stuff in and out of the XML parser functions.
	var $remoteUpdate;
	var $localPosts;
	var $foundPosts;
	var $mirrorStats;

	// MySQL stuff.
	var $mysqlLink;
	var $mysqlTable;

	// Can be used to force an update regardless of what Delicious says.
	var $forceUpdate;

	// Should we escape HTML characters if needed?
	var $outputMode;

	// XML returned by Delicious should be included in the output.
	var $logXml;

	// https://api.del.icio.us/v1/ ... Or whatever it needs to be.
	var $deliciousAPIPrefix;

	// If we start getting throttled, this will let us back off and get it cleared (hopefully).
	var $deliciousThrottleBackoffMultiplier;

	// Gets set to either "<br />" or "\n".
	var $newline;

	// =============================================================================================================
	//                                               MySQLicious
	// =============================================================================================================

	// -------------------------------------------------------------------------------------------------------------
	// MySQLicious constructor
	// Parameters:
	//  $mysqlHost		Address of your MySQL server - usually "localhost".
	//  $mysqlDatabase	MySQL database in which your Delicious bookmarks will be stored.
	//  $mysqlUsername	Username for MySQL.
	//  $mysqlPassword	Password for MySQL.
	function MySQLicious($mysqlHost, $mysqlDatabase, $mysqlUsername, $msyqlPassword) {
		// Set up some defaults.
		$this->remoteUpdate = "";
		$this->localPosts = array();
		$this->foundPosts = array();
		$this->mirrorStats = array();
		$this->deliciousThrottleBackoffMultiplier = 2;
		$this->out = "";

		// Set the Delicious API URL.
		$this->setAPIAddress("https://api.del.icio.us/v1/");

		// Data table for storing update dates.
		$this->MySQLiciousDataTable = "MySQLicious";

		// By default, we don't want to force an update or include XML in output.
		$this->forceUpdate = false;
		$this->logXml = false;

		// If $_ENV['SHELL'] exists, we're probably in command line mode.
		if (array_key_exists('SHELL', $_ENV)) {
			$this->setOutputMode(MYSQLICIOUS_OUTPUT_CMD);
		} else {
			$this->setOutputMode(MYSQLICIOUS_OUTPUT_HTML);
		}

		// Connect to the MySQL server and select the database.
		$this->mysqlLink = mysql_connect($mysqlHost, $mysqlUsername, $msyqlPassword) or die("Could not connect to MySQL server.");
		$db = mysql_select_db($mysqlDatabase, $this->mysqlLink) or die("Could not select specified database ($mysqlDatabase).");
	}

	// -------------------------------------------------------------------------------------------------------------
	// mirror - Kick off mirroring of a Delicious account.
	// Parameters:
	//  $deliciousUsername	Username for Delicious.
	//  $deliciousPassword	Password for Delicious.
	//  $mysqlTable		MySQL table into which the Delicious bookmarks should be mirrored.
	//			NOTE: It will be created automatically if it doesn't exist.
	//  $deliciousTag	[optional] Tag to filter by. If not specified, all bookmarks will be mirrored.
	function mirror($deliciousUsername, $deliciousPassword, $mysqlTable, $deliciousTag = "") {
		$this->mysqlTable = $mysqlTable;

		// Make sure that the necessary MySQL tables exist.
		$this->mysqlCheckTables();

		// Try find out the last time we updated.
		// If we can't find the value, $localUpdate will be set to false.
		$localUpdate = $this->mysqlGetLocalUpdate($deliciousTag);

		// Find out the last time Delicious was updated.
		$this->deliciousAPI("posts/update", $deliciousUsername, $deliciousPassword);

		// Let's do some updating!
		if ((($this->remoteUpdate > $localUpdate) or !$localUpdate or $this->forceUpdate) and !$this->isError) {
			$this->out .= "Update may be needed. Checking now." . $this->newline . $this->newline;

			// Gather all posts from the MySQL mirror for comparison to the Delicious ones.
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

			// Tracking vars for stats.
			$this->mirrorStats['numInserted'] = 0;
			$this->mirrorStats['numUpdated'] = 0;
			$this->mirrorStats['numDeleted'] = 0;

			// Grab all posts from Delicious.
			$APIParam = (strlen($deliciousTag) > 0) ? "?tag=$deliciousTag" : "";
			$this->deliciousAPI("/posts/all$APIParam", $deliciousUsername, $deliciousPassword);

			// If there are any posts that exist in MySQL but not on Delicious, get rid of the local copies.
			$deletePosts = array_diff(array_keys($this->localPosts), array_keys($this->foundPosts));
			if (count($deletePosts) > 0) {
				foreach ($deletePosts as $deleteMe) {
					$this->mysqlDeliciousDelete($deleteMe);
				}
			}

			// We've updated, so let's record that.
			$this->mysqlSetLocalUpdate($deliciousTag);

			// Display stats if there are any.
			if (($this->mirrorStats['numInserted'] + $this->mirrorStats['numUpdated'] + $this->mirrorStats['numDeleted']) > 0) {
				if ($this->mirrorStats['numInserted'] > 0) {
					$this->out .= $this->newline . $this->mirrorStats['numInserted'] . " inserted." . $this->newline;
				}
				if ($this->mirrorStats['numUpdated'] > 0) {
					$this->out .= $this->newline . $this->mirrorStats['numUpdated'] . " updated." . $this->newline;
				}
				if ($this->mirrorStats['numDeleted'] > 0) {
					$this->out .= $this->newline . $this->mirrorStats['numDeleted'] . " deleted." . $this->newline;
				}
			} else {
				$this->out .= "No items inserted or updated.";
			}

		} elseif ($this->isError) {
			$this->out .= $this->errorText;
		} else {
			$this->out .= "No update needed.";
		}

		$this->out .= $this->newline . $this->newline;

		if ($this->outputMode != MYSQLICIOUS_OUTPUT_NONE) {
			echo $this->out;
		}
	}

	// -------------------------------------------------------------------------------------------------------------
	// xml_start_element - Parse the stuff handed back by the Delicious API.
	// See http://www.php.net/xml-set-element-handler for why how this relates to XML parsing.
	function xml_start_element($parser, $name, $attrs) {
		switch ($name) {
			// If we are parsing /posts/update, we'll hit an <update time=""> tag.
			// Grab that value and record it.
			case "update":
				$this->remoteUpdate = $this->time_deliciousToTimestamp($attrs['time']);
				break;

			// If we are parsing /posts/all, we'll hit <post ...> tags.
			case "post":

				// If the post doesn't exist in MySQL, insert it.
				if (!array_key_exists($attrs['hash'], $this->localPosts)) {
					$this->mysqlDeliciousInsert($attrs);
				// If some part of the post in MySQL doesn't match the Delicious version, update it.
				} elseif ($this->localPosts[$attrs['hash']]['description'] != $attrs['description'] or
						  $this->localPosts[$attrs['hash']]['extended'] != $attrs['extended'] or
						  $this->localPosts[$attrs['hash']]['tags'] != $attrs['tag']) {
					$this->mysqlDeliciousUpdate($attrs);
				}

				// Record the fact that we've found this post -- otherwise it'll be deleted.
				$this->foundPosts[$attrs['hash']] = true;

				break;
		}
	}

	// -------------------------------------------------------------------------------------------------------------
	// We don't need to do anything when we hit the end of an XML element, but this function has to exist.
	function xml_end_element($parser, $name) {}

	// -------------------------------------------------------------------------------------------------------------
	// convertDeliciousTagForMysql - When recording the last update time, use the value set by this function if
	//                               there is no tag filter set.
	// Paramenters:
	//  $tag		Tag set by user.
	function convertDeliciousTagForMysql($tag) {
		return (strlen($tag) > 0) ? $tag : "MySQLiciousWithNoTagFilter";
	}

	// -------------------------------------------------------------------------------------------------------------
	// setOutputHTML - Lets you decide if you want "<br />" or "\n" at the end of lines. Default is "\n".
	// Paramenters:
	//  $useHTML		True means line endings will be "<br />", false means "\n".
	function setOutputMode($mode) {
		$this->outputMode = $mode;
		$this->newline = ($mode == MYSQLICIOUS_OUTPUT_HTML) ? "<br />" : "\n";
	}

	// =============================================================================================================
	//                                       Delicious API Functions
	// =============================================================================================================

	// -------------------------------------------------------------------------------------------------------------
	// setAPIAddress - Change the API address.
	// Parameters:
	//  $delAPI		Delicious API URL.
	function setAPIAddress($delAPI) {
		if ($delAPI{strlen($delAPI) - 1} != "/") {
			$delAPI .= "/";
		}

		$this->deliciousAPIPrefix = $delAPI;
	}

	// -------------------------------------------------------------------------------------------------------------
	// deliciousAPI - Make an API call.
	// Parameters:
	//  $apiCall		Call being made, in the format of "api/call" - note that there is no slash at the beginning.
	//  $user		Delicious username.
	//  $pass		Delicious password.
	function deliciousAPI($apiCall, $user, $pass) {
		// We need to recreate the XML parser every time because there's a (silly) PHP bug where you can't reuse a parser.
		$p = $this->createXMLParser();

		// Parse the Delicious content returned from the API.
		$theXML = $this->deliciousDoAPI($apiCall, $user, $pass);
		if ($theXML['success']) {
			// Include XML returned by Delicious if we need to.
			if ($this->logXml) {
				$this->out .= $this->newline;
				$this->out .= ($this->outputMode == MYSQLICIOUS_OUTPUT_HTML) ?
								str_replace(array("<", ">"), array("&lt;", "&gt;"), $theXML['page']) :
								$theXML['page'];
				$this->out .= $this->newline . $this->newline;
			}

			if (!xml_parse($p, $theXML['page'], true)) {
				$this->out .= "XML Parse Error: " . xml_error_string(xml_get_error_code($p)) . " at line " . xml_get_current_line_number($p) . $this->newline;
			}
		} else {
			$this->isError = true;

			$text = preg_replace('/</', ' <', $theXML['page']);
			$text = preg_replace('/>/', '> ', $text);
			$text = html_entity_decode(strip_tags($text));
			$text = preg_replace('/[\n\r]/', $this->newline, $text);

			$this->errorText  = "Delicious API Error:" . $this->newline . $this->newline;
			$this->errorText .= trim($text);
		}

		xml_parser_free($p);
	}

	// -------------------------------------------------------------------------------------------------------------
	// deliciousDoAPI - Just a wrapper to make API actions a little prettier.
	// Parameters:
	//  $apiCall		Call being made, in the format of "api/call" - note that there is no slash at the beginning.
	//  $user		Delicious username.
	//  $pass		Delicious password.
	function deliciousDoAPI($apiCall, $user, $pass) {
		return $this->curlIt($this->deliciousAPIPrefix.$apiCall, $user, $pass);
	}

	// -------------------------------------------------------------------------------------------------------------
	// curlIt - Grab a URL with CURL.
	// Parameters:
	//  $url		Full URL to fetch.
	//  $user		Delicious username.
	//  $pass		Delicious password.
	function curlIt($url, $user, $pass) {
		$ch = curl_init();

		$ret = array();

		curl_setopt($ch, CURLOPT_URL, $url);			// This is the page we're grabbing.
		curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");	// HTTP auth username/password.
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);		// We want the data passed back.
		curl_setopt($ch, CURLOPT_USERAGENT, "MySQLicious");	// Set our useragent because Delicious likes it that way.

		$tryCount = 0;
		do {
			// Grab the API return value.
			$page = curl_exec($ch);

			$tryCount++;

			// Record the HTTP code.
			$HTTPCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			// If we get throttled, wait for a while before trying again.
			if ($HTTPCode == 503) {
				$sleepytime = $this->deliciousThrottleBackoffMultiplier * 5;

				$this->out .= "WARNING: You are being throttled by the Delicious server. Pausing for $sleepytime seconds." . $this->newline;
				sleep($sleepytime);

				$this->deliciousThrottleBackoffMultiplier = pow($this->deliciousThrottleBackoffMultiplier, 2);
				$tryAgain = true;

			} else {
				$tryAgain = false;
			}

		} while ($tryAgain and $tryCount < 5);

		curl_close($ch);

		// If we got a 200 code, the request was successful. Otherwise, it wasn't.
		$ret['success'] = ($HTTPCode == 200);
		$ret['page'] = trim($page);
		return $ret;
	}

	// -------------------------------------------------------------------------------------------------------------
	// createXMLParser - Since we have to create a new XML parser for each API call, let's wrap it to make it pretty.
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
	// mysqlCheckTables - Make sure our data tables exist. If not, create them.
	function mysqlCheckTables() {
		// Make sure the MySQLicious data table exists.
		if (!$this->mysqlTableExists($this->MySQLiciousDataTable)) {
			$sql  = "CREATE TABLE `".$this->MySQLiciousDataTable."` (`tag` varchar(255) NOT NULL default '', ";
			$sql .= "`lastupdate` datetime default NULL, PRIMARY KEY (`tag`)) TYPE=MyISAM";
			$result = mysql_query($sql, $this->mysqlLink) or die("Unable to create table to store MySQLicious data.");
		}

		// Make sure the table we're going to stick posts in exists.
		if (!$this->mysqlTableExists($this->mysqlTable)) {
			$sql  = "CREATE TABLE `".$this->mysqlTable."` (`id` int(11) NOT NULL auto_increment, `url` text, `description` text, ";
			$sql .= "`extended` text, `tags` text, `date` datetime default NULL, `hash` varchar(255) default NULL, ";
			$sql .= "PRIMARY KEY  (`id`), KEY `date` (`date`)) TYPE=MyISAM";
			$result = mysql_query($sql, $this->mysqlLink) or die("Unable to create ".$this->mysqlTable." to store Delicious posts.");
		}
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlGetLocalUpdate - Find out the last time an update was done for the given tag.
	// Parameters:
	//  $tag		Tag to check on.
	function mysqlGetLocalUpdate($tag) {
		$sql = "SELECT `lastupdate` FROM `".$this->MySQLiciousDataTable."` WHERE `tag` = \"".$this->convertDeliciousTagForMysql($tag)."\" LIMIT 1";

		$result = mysql_query($sql, $this->mysqlLink) or die("\n" . mysql_error($this->mysqlLink) . "\n$sql\n");

		if (mysql_num_rows($result) == 1) {
			$row = mysql_fetch_assoc($result);
			return strtotime($row['lastupdate']);
		} else {
			// If no result, we've never updated and need to.
			return false;
		}
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlSetLocalUpdate - Record that we've done an update.
	// Parameters:
	//  $tag		Tag to set update time for.
	function mysqlSetLocalUpdate($tag) {
		$tag = $this->convertDeliciousTagForMysql($tag);
		$remoteUpdate = $this->time_timestampToMysql($this->remoteUpdate);

		if ($this->mysqlGetLocalUpdate($tag) != false) {
			// Already exists, need to update.
			$sql = "UPDATE `".$this->MySQLiciousDataTable."` SET `lastupdate` = \"$remoteUpdate\" WHERE `tag` = \"$tag\"";
		} else {
			// First time, need to insert.
			$sql = "INSERT INTO `".$this->MySQLiciousDataTable."` (`tag`, `lastupdate`) VALUES (\"$tag\", \"$remoteUpdate\")";
		}
		$result = mysql_query($sql, $this->mysqlLink) or die("\n" . mysql_error($this->mysqlLink) . "\n$sql\n");
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousUpdate - Update the local copy of a post.
	// Parameters:
	//  $attrs		Attributes from XML <post ...> element.
	function mysqlDeliciousUpdate($attrs) {
		$sql  = "UPDATE `".$this->mysqlTable."` SET ";
		$sql .= "`description` = '" . $this->escapeSingleQuotes($attrs['description']) . "', ";
		$sql .= "`extended` = '" . $this->escapeSingleQuotes($attrs['extended']) . "', ";
		$sql .= "`tags` = '" . $this->escapeSingleQuotes($attrs['tag']) . "' ";
		// Uncomment the next line to change the item's date (I've had issues where Delicious changes the item date when it shouldn't so I disabled this).
		// $sql .= "`date` = '" . $this->time_deliciousToMysql($attrs['time']) . "' ";
		$sql .= "WHERE `hash` = '{$attrs['hash']}'";

		//$result = mysql_query($sql, $this->mysqlLink);
		$this->mysqlDoQuery($sql, "Updated {$attrs['href']}", $this->mirrorStats['numUpdated']);
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousInsert - Insert a post from Delicious into MySQL.
	// Parameters:
	//  $attrs		Attributes from XML <post ...> element.
	function mysqlDeliciousInsert($attrs) {
		$sql  = "INSERT INTO `".$this->mysqlTable."` (`hash`, `url`, `description`, `extended`, `tags`, `date`) ";
		$sql .= "VALUES (";
		$sql .= "'" . $this->escapeSingleQuotes($attrs['hash']) . "', ";
		$sql .= "'" . $this->escapeSingleQuotes($attrs['href']) . "', ";
		$sql .= "'" . $this->escapeSingleQuotes($attrs['description']) . "', ";
		$sql .= "'" . $this->escapeSingleQuotes($attrs['extended']) . "', ";
		$sql .= "'" . $this->escapeSingleQuotes($attrs['tag']) . "', ";
		$sql .= "'" . $this->time_deliciousToMysql($attrs['time']);
		$sql .= "')";

		$this->mysqlDoQuery($sql, "Inserted {$attrs['href']}", $this->mirrorStats['numInserted']);
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlDeliciousDelete - Remove a post from MySQL.
	// Parameters:
	//  $hash		MD5 hash of the URL to be removed.
	function mysqlDeliciousDelete($hash) {
		$sql = "DELETE FROM `".$this->mysqlTable."` WHERE `hash` = '$hash' LIMIT 1";

		$this->mysqlDoQuery($sql, "Deleted $hash", $this->mirrorStats['numDeleted']);
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlTableExists - Check to see if $table exists.
	function mysqlTableExists($table) {
		$exists = mysql_query("SELECT 1 FROM `$table` LIMIT 0", $this->mysqlLink);
		return ($exists) ? true : false;
	}

	// -------------------------------------------------------------------------------------------------------------
	// mysqlDoQuery - Decides what to display based on value of $result.
	// Parameters:
	//  $sql		Query to execute.
	//  $textIfGood		What do we display if the result is good (if it's bad it shows the MySQL error code).
	//  &$incrementThis	Variable to increment on a good result.
	function mysqlDoQuery($sql, $textIfGood, &$incrementThis) {
		if (mysql_query($sql, $this->mysqlLink)) {
			$this->out .= $textIfGood . $this->newline;
			$incrementThis ++;
		} else {
			$this->out .= $this->newline;
			$this->out .= mysql_error($this->mysqlLink);
			$this->out .= $this->newline . $sql . $this->newline . $this->newline;
		}
	}

	// =============================================================================================================
	//                                   Time and Text Conversion Functions
	// =============================================================================================================

	// -------------------------------------------------------------------------------------------------------------
	// time_deliciousToTimestamp - Convert $time given by Delicious API call to a UNIX timestamp.
	function time_deliciousToTimestamp($time) {
		return strtotime(ereg_replace('T|Z',' ', $time));
	}

	// -------------------------------------------------------------------------------------------------------------
	// time_deliciousToMysql - Convert $time given by Delicious API call to MySQL friendly datetime.
	function time_deliciousToMysql($time) {
		return $this->time_timestampToMysql($this->time_deliciousToTimestamp($time));
	}

	// -------------------------------------------------------------------------------------------------------------
	// time_timestampToMysql - Convert $time UNIX time to MySQL friendly datetime.
	function time_timestampToMysql($timestamp) {
		return date("Y-m-d H:i:s", $timestamp);
	}

	// -------------------------------------------------------------------------------------------------------------
	// escapeSingleQuotes - Convert ' to \'.
	function escapeSingleQuotes($str) {
		return str_replace("'", "\'", $str);
	}
}

?>
