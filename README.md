**English** | [Português](README.pt-BR.md)

# PipStore

A simple site for authors to publish their stories.

It manages series, books and chapters, in multiple languages. It lets you customize the accent color and gives readers control over the experience: light/dark mode, serif or sans-serif font, text size.

More immersion in the stories with optional illustrations and soundtrack.

It highlights the last chapter read in each book, making it easy to pick up reading in separate sections. It also includes a links section for the author's bio.

Built with LLM assistance, but all code is reviewed before going live.

## Installation

There is no web installer; setup takes three steps. Requirements: PHP with `mysqli` and MySQL/MariaDB.

1. **Extract the files** into your web server's public folder (e.g. `public_html/`, `htdocs/` or `/var/www/html/`). Make sure `uploads/` is writable by the web server.
2. **Create the database** by importing `install.sql` (phpMyAdmin → *Import*, or `mysql -u root -p < install.sql`). It creates the `kiyoshipip` database and all tables. If your host gives you a pre-made database, remove the first lines (`CREATE DATABASE` and `USE`) and import it into that one.
3. **Edit `config.php`** with your database access data: `DB_HOST`, `DB_USER`, `DB_PASS` and `DB_NAME`. You can also change `SITE_NAME` there.

Then open `https://your-site/admin.php` and log in with the default password **`changeme`**. Change it right away. Any pending migrations from `migrations/` are applied automatically when you open the admin. The public site is at `index.php`.
