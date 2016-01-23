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
require '../relational_code_gen.php';
require 'config.php';
$db = reDBFactory::connect($db_config);

$dbcg = new reDBCodeGen( $db, 'ex' );
$dbcg->setUseFKConstraintNames(true);
$dbcg->setTableHints( [
	'staff_list' => [ 'order_by'=>'name,city' ]
] );
$generated_code = $dbcg->generateAll();

echo '<pre style="font-size:8px; font-family:monospace">';

$lines = preg_split('/\r\n|\r|\n/',htmlspecialchars($generated_code));
foreach( $lines as $n=>$line ) {
	echo $n.': '.$line."\n";
}


echo "<hr />";
eval('?>'.$generated_code);

$root = new exRoot($db);

/***
echo "static call: exActor::_getMetadata()\n\n";
var_dump(exActor::_getMetadata());
echo "first entity, direct call: exActor::_getClassMetadata()\n\n";
$item = $root->getActor(1);
var_dump($item->_getClassMetadata());
***/

?>
</body>
</html>
