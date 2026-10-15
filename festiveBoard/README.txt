FESTIVE BOARD - install notes

1. Upload this whole folder to your site (for example public_html/festive-board/).
2. Needs PHP 7.4 or newer. Nothing else to install.
3. Make the data/ folder writable by PHP (usually chmod 755 or 775; use 775/777 only if saving fails).
4. Open /festive-board/ in the browser.
5. Protect it: set $BOARD_KEY in config.php (password prompt), or put the folder behind your site's login.

All data is in data/db.json (members, seating, date). Back it up by copying that file.
The .htaccess files block direct web access to data/ on Apache. On nginx add:
    location ~ ^/festive-board/data/ { deny all; }
