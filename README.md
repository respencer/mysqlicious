MySQLicious
===========

A Delicious to MySQL mirroring tool.

MySQLicious provides automated mirroring/backups of
[Delicious](https://delicious.com) bookmarks into a MySQL database.

Note that MySQLicious does not provide a mechanism for displaying
bookmarks after they're in your database; that part is up to you.


Requirements
------------

PHP 4 with cURL support
MySQL
Access to cron (or [pseudo-cron](http://www.bitfolge.de/pseudocron-en.html)).
A [Delicious](https://delicious.com) account and some bookmarks.


Usage
-----

MySQLicious is designed to be run as a cron job. See the tutorial for
more information. Here's a quick example of how MySQLicious is used:

```php
<?php
require "MySQLicious.php";
$delicious = new MySQLicious("localhost", "MySQLdb", "MySQLuser", "MySQLpass");
$delicious->mirror("deliciousUser", "deliciousPass", "MySQLtable", "deliciousTag");
?>
```


Warning
-------

Do not run MySQLicious on every page load or you'll get banned from
the del.icio.us server. See the
[tutorial](https://github.com/respencer/mysqlicious/wiki/Tutorial)
for information on proper setup.


For more documentation, see the
[wiki](https://github.com/respencer/mysqlicious/wiki).
