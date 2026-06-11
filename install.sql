-- Site de Histórias — esquema completo
-- Senha padrão do admin: changeme  (altere em admin.php após o primeiro login)

CREATE DATABASE IF NOT EXISTS kiyoshipip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kiyoshipip;

CREATE TABLE languages (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(10)  NOT NULL,
  name     VARCHAR(50)  NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE series (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(100) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE series_t (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  series_id   INT UNSIGNED NOT NULL,
  lang_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(200) NOT NULL,
  description TEXT,
  UNIQUE KEY uq_series_lang (series_id, lang_id),
  FOREIGN KEY (series_id) REFERENCES series(id)    ON DELETE CASCADE,
  FOREIGN KEY (lang_id)   REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE books (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  series_id   INT UNSIGNED NOT NULL,
  slug        VARCHAR(100) NOT NULL,
  cover_image VARCHAR(500),
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug),
  FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE books_t (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id     INT UNSIGNED NOT NULL,
  lang_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(200) NOT NULL,
  copyright   VARCHAR(300),
  description TEXT,
  UNIQUE KEY uq_book_lang (book_id, lang_id),
  FOREIGN KEY (book_id)  REFERENCES books(id)     ON DELETE CASCADE,
  FOREIGN KEY (lang_id)  REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE chapters (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id    INT UNSIGNED NOT NULL,
  slug       VARCHAR(100) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug),
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE chapters_t (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chapter_id  INT UNSIGNED NOT NULL,
  lang_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(200) NOT NULL,
  content     LONGTEXT,
  UNIQUE KEY uq_chapter_lang (chapter_id, lang_id),
  FOREIGN KEY (chapter_id) REFERENCES chapters(id)  ON DELETE CASCADE,
  FOREIGN KEY (lang_id)    REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE bio_links (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(100) NOT NULL,
  url        VARCHAR(500) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE site_settings (
  `key`   VARCHAR(50) NOT NULL PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB CHARSET=utf8mb4;

CREATE TABLE home_t (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lang_id INT UNSIGNED NOT NULL,
  title   VARCHAR(200) NOT NULL DEFAULT '',
  content LONGTEXT,
  UNIQUE KEY uq_lang (lang_id),
  FOREIGN KEY (lang_id) REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Dados iniciais
INSERT INTO languages (code, name, is_default) VALUES ('pt', 'Português', 1);
INSERT INTO admin_users (username, password_hash) VALUES
  ('admin', '$2y$10$MGty3O44fDBNpTWFJ2sX5OmErv7DyBOMzRH5T8TBt8nuX2IB1F7je');
INSERT INTO site_settings (`key`, `value`) VALUES
  ('site_name',    'Histórias'),
  ('accent_color', '#2e7d52'),
  ('logo_url',     '');
