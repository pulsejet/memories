# Memories Development Container

This is a Nextcloud development container with Memories pre-installed.

After the container starts up, follow these steps:

1. Disable the built-in PHP extension of VS Code. Search for `@builtin php-language-features` in the extensions tab and disable it.
1. You can log in to Nextcloud using the following credentials:
   - Username: `admin`
   - Password: `admin`

Note: MariaDB is set up automatically (db=`nextcloud`, user=`nextcloud`, password=`nextcloud`); Adminer for graphical database management is available on port 8080.

To run OCC commands in the container, use the following command:

```bash
sudo -E -u www-data php /var/www/html/occ <command>
```

To watch changes in UI build:
    
```bash
make watch-js
```
