# relational
minimalistic DRY database layer for PHP, with adaptors for PostgreSQL and MySQL
and lightweight ORM

## Yet another database abstraction layer ?

Yes, but in contrast to other libraries, this one is based on a minimalistic approach, its concise and has just as little as possible overhead.


## Why not PDO ?

PDO is rather complex, provides many choices and its interfaces are unnecessarily complex.

For example:

#### PDO, using prepared statement(*):

```PHP
$db = new PDO($dsn,$username,$password);
$statement = $db->prepare('SELECT * FROM users WHERE id=:id');
$statement->execute( [ ':id' => $id ] );
while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
    echo $row['id'] . ' ' . $row['name'];
}
```

#### PDO, plain (you must pass parameters directly in sql string, and care about escaping):

```PHP
$db = new PDO($dsn,$username,$password);
$rs = $db->query('SELECT * FROM users WHERE id='.$db->quote($id));
foreach( $rs as $row ) {
    echo $row['id'] . ' ' . $row['name'];
}
```

#### relational:

```PHP
$db = reDBFactory::connect($config_string);
foreach( $db->queryAssoc('SELECT * FROM users WHERE id=?',array($id)) as $row ) {
    echo $row['id'] . ' ' . $row['name'];
}
```

Please notice that query execution, escaping of parameters and fetching of result rows is combined into a single call to queryAssoc().

That's it. Concise.


Besides queryAssoc() there are additional query methods for casual purposes: `queryArray(), queryOneArray(), queryOneAssoc(), exec(), queryOneValue(), queryOneColumn(), queryKeyValueArray()`, as well as methods for transaction control: `begin(), commit(), rollback()`.


### (*) Why not PDO, really ?

PDO has a design-flaw with inacceptable long-term consequences: to use its parameter binding interface it requires using "prepared statements". The PDO creators even encourage developers to use prepared statements everywhere. That contradicts their purpose. PDO abuses prepared statements for parameter binding in lack of a sober parameter binding interface for non-prepared statements.


#### Safe Parameter Binding !

"relational" provides a real parameter binding interface for PHP.

It intentionelly does NOT provide functions for escaping data for use in SQL (as e.g. `mysqli_real_escape_string()`). There is no need for it, all parameters must be passed through the parameter binding interface.


### Why no "fetch row" methods ? Doesn't it consume huge amounts of memory when always fetching whole result at once ?

No! Actually the mysqli and pgsql interface libraries (by default) do fetch the whole result anyway, whenever user requests to fetch the first row.

So it really does not matter. Fetching results row-by-row in PHP is just unnecessary overhead.

