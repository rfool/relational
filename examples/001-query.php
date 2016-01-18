<?php
echo '<'.'?'.'xml version="1.0" encoding="UTF-8"'.'?'.'>';
?>
<!DOCTYPE html>
<html>
<head>
<title><?php echo basename(__FILE__); ?></title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
</head>
<body>
<h1><?php echo basename(__FILE__); ?></h1>
<?php

require '../relational.php';
require 'config.php';
$db = reDBFactory::connect($db_config);

var_dump( $db->queryAssoc('SELECT * FROM customer_list ORDER BY id') );

?>
</body>
</html>
