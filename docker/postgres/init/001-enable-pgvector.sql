-- Enable pgvector extension in the target database.
-- Runs automatically on first container init (empty data volume).
CREATE EXTENSION IF NOT EXISTS vector;
