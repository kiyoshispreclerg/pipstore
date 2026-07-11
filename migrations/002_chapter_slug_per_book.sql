ALTER TABLE chapters DROP INDEX uq_slug, ADD UNIQUE KEY uq_slug (book_id, slug);
