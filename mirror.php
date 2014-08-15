#!/usr/bin/php -q
<?php
// mirror.php MySQLicious implementation
// Mirrors Delicious bookmarks.
// v1.01 - 8/6/2006

// MySQL configuration.
$MySQL_Host	= "localhost";	// Address of your MySQL server.
$MySQL_Database	= "db";		// Name of the MySQL database you want to use.
$MySQL_Table	= "delicious";	// Name of the MySQL table you want to put the Delicious bookmarks in.
$MySQL_Username	= "username";	// MySQL username.
$MySQL_Password	= "password";	// MySQL password.

// Delicious configuration.
$delicious_Username	= "username";	// Delicious username.
$delicious_Password	= "password";	// Delicious password.
$delicious_TagFilter	= "";		// Tag to mirror. If left blank, all bookmarks will be mirrored.

// ---------------------------------------------------------------
//  You shouldn't need to change anything below here.
// ---------------------------------------------------------------

// Import the MySQLicious code.
$currentDir = dirname(__FILE__)."/";
require $currentDir."MySQLicious.php";

// Initialize MySQLicious.
$delicious = new MySQLicious($MySQL_Host, $MySQL_Database, $MySQL_Username, $MySQL_Password);

// Un-comment the following line to turn on XML logging.
// This should only be necessary as a debugging measure.
//$delicious->logXml = true;

// Perform the mirroring.
$delicious->mirror($delicious_Username, $delicious_Password, $MySQL_Table, $delicious_TagFilter);

?>
