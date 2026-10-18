# Administration

Notes for running and maintaining gistats. Add a section here for each admin task worth writing down.

## Demo user with urgency data

`bin/seed_demo.php` creates a demo user so you can see what the statistics charts look like with urgency data, without waiting for real entries to pile up.

- **Login:** username `urgency`, password `password`
- **Data:** about 500 fake entries spread over the past year, with some empty days. Urgency is much more likely on loose stools (Types 5 to 7), so the heat map shows a visible pattern.
- **Tagging:** every demo entry has the note `demo data`. That note is how the script tells demo entries from real ones.

### Create or refresh

```sh
php bin/seed_demo.php
```

The first run creates the user. Later runs reset the password and replace the demo entries with a fresh year of data ending today. It's safe to run repeatedly, and it never creates duplicates.

### Remove

```sh
php bin/seed_demo.php --remove
```

This deletes the `urgency` user and its demo entries.

### Safety

- The script only touches the user named `urgency`. Other users and their entries are left alone.
- If `urgency` has any entries that aren't tagged `demo data`, both the seed and `--remove` refuse to run and exit with an error. Delete or move those entries by hand first.
- The script uses the database from `config.php`. By default that's the real `database.sqlite`, and the script doesn't print which file it's using. See [limitations](limitations.md).

### Before deploying

The demo account has a weak, well-known password. Don't let it reach production. If you copy a local `database.sqlite` to a server, run `--remove` on the copy first.

To check whether the demo user is present in a database:

```sh
sqlite3 database.sqlite "SELECT username FROM users;"
```
