# MyISAM2InnoDB.php
## PHP script to convert MyISAM tables into InnoDB on a bulk scale

### Summary
- Run via CLI using php
- Scan only (--run) or automatically convert (--fix)
- Perform backups prior to conversion (--backup)
- Email results (--email)
- Only show found MyISAM tables

### Requirements
This script only needs 2 things to work, PHP and local linux mysql tooling (mysql/mysqldump).

## Installation

Example commands:
```sh
php myisam2innodb.php --run --single mydatabase
```

## ToDo

- Use PDO instead of MySQLi
- Use PHP for generating backups instead of MySqlDump
