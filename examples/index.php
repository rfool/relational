<?php
echo '<'.'?'.'xml version="1.0" encoding="UTF-8"'.'?'.'>';
?>
<!DOCTYPE html>
<html>
<head>
<title>relational - lightweight database abstraction layer - examples</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="description" content="relational - a lightweight database abstraction layer" />
<meta name="author" content="Robert Frunzke" />
<meta name="keywords" content="SQL, Database, Adapter, Interface, ORM" />
</head>
<body>
<h1>relational - a lightweight database abstraction layer</h1>
<h2>examples</h2>
<ol>
<?php
if( ($handle=opendir('.')) ) {
	while( ($entry=readdir($handle))!==false ) {
		if( $entry!=='.' && $entry!=='..' && $entry!=='index.php' && $entry!=='config.php' ) {
			echo '<li><a href="'.$entry.'">'.$entry.'</a></li>';
		}
	}
}
?>
</ol>
</body>
</html>
