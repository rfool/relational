# relational
cute schema-aware DRY database layer for PHP, with adaptors for PostgreSQL and MySQL

# Why not PDO ?

PDO is rather complex, provides many choices and so its interfaces are rather complex. Unnecessarily complex.

For example:

```
$connection = new PDO($dsn,$username,$password);
$statement = $connection->prepare('SELECT * FROM users WHERE id=:id');
$statement->execute( [ ':id' => $id ] );
while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
    echo $row['id'] . ' ' . $row['name'];
}
```

With "relational" this can be written much more straight and compact:

```
$db = reDBFactory::connect($config_string);
foreach( $db->queryAssoc('SELECT * FROM users WHERE id=?',array($id)) as $row ) {
    echo $row['id'] . ' ' . $row['name'];
}
```

Please notice that query execution, escaping of parameters and fetching of result rows is combined into a single call to queryAssoc().

Well, that's it.

Besides queryAssoc() there are additional query methods for casual purposes: queryArray(), queryOneArray(), queryOneAssoc(), exec(), queryOneValue(), queryOneColumn(), queryKeyValueArray(), as well as methods for transaction control: begin(), commit(), rollback().


# Why not PDO, really ?

Also, PDO has a flaw with long-term consequences: to use its parameter binding interface it requires using "prepared statements" and encourages developers to use prepared statements everywhere. That contradicts their purpose. PDO clearly abuses prepared statements for parameter binding in lack of a sober parameter binding interface for non-prepared statements.

