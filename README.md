# relational
cute schema-aware DRY database layer for PHP, with adaptors for PostgreSQL and MySQL

## Why not PDO ?

PDO is rather complex, provides many choices and so its interfaces are rather complex. Unnecessarily complex.

For example:

```PHP
$connection = new PDO($dsn,$username,$password);
$statement = $connection->prepare('SELECT * FROM users WHERE id=:id');
$statement->execute( [ ':id' => $id ] );
while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
    echo $row['id'] . ' ' . $row['name'];
}
```

With "relational" this can be written much more straight and compact:

```PHP
$db = reDBFactory::connect($config_string);
foreach( $db->queryAssoc('SELECT * FROM users WHERE id=?',array($id)) as $row ) {
    echo $row['id'] . ' ' . $row['name'];
}
```

Please notice that query execution, escaping of parameters and fetching of result rows is combined into a single call to queryAssoc().

Well, that's it.

Besides queryAssoc() there are additional query methods for casual purposes: queryArray(), queryOneArray(), queryOneAssoc(), exec(), queryOneValue(), queryOneColumn(), queryKeyValueArray(), as well as methods for transaction control: begin(), commit(), rollback().


### Why not PDO, really ?

PDO has a flaw with long-term consequences: to use its parameter binding interface it requires using "prepared statements" and encourages developers to use prepared statements everywhere. That contradicts their purpose. PDO clearly abuses prepared statements for parameter binding in lack of a sober parameter binding interface for non-prepared statements.

#### Safe Parameter Binding !

It seems like "relational" is the first and only PHP database abstraction layer with a real safe parameter binding interface! This can't be true, right? Right? Really?

BTW: "relational" intentionelly does not provide functions for escaping data for use in SQL (as e.g. mysqli_real_escape_string()). Because there is no need for it! You could even say, "relational" protects you from yourself by not even offering you the tools that could do harm in the first place.


### Why no "fetch row" methods ? Doesn't it consume huge amounts of memory when we always fetch whole result at once ?

No! Actually the mysqli and pgsql interface libraries (by default) do fetch the whole result when user requests to fetch the first row, anyway.

So it really does not matter. The code to fetch results row-by-row in PHP is just overhead, hidden behind boilerplate code.

