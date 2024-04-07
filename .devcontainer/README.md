# Memories Development Container

This is a Nextcloud development container with Memories pre-installed.

Username: `admin`
Password: `admin`

Database: MariaDB (db=`nextcloud`, user=`nextcloud`, password=`nextcloud`)

After the container starts up, make sure to disable the built-in PHP extension of VS Code. Search for `@builtin php-language-features` in the extensions tab and disable it.

To run OCC commands in the container, use the following command:

```bash
sudo -E -u www-data php /var/www/html/occ <command>
```

To watch changes in UI build:
    
```bash
make watch-js
```