ALTER TABLE documents ADD COLUMN publish_at TEXT;
ALTER TABLE documents ADD COLUMN public_id TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_documents_public_id
ON documents(public_id);

CREATE INDEX IF NOT EXISTS idx_documents_title
ON documents(title);