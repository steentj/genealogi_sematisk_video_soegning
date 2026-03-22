-- =============================================================================
-- Semantisk videosøgning for genealogi.dk
-- Tabel-definition til lagring af tekst og embeddings
-- =============================================================================
--
-- Kør dette script én gang for at oprette tabellen i din MySQL-database.
-- Eksempel: mysql -u brugernavn -p databasenavn < schema.sql
--
-- Embeddings gemmes som JSON-arrays (strenge) i LONGTEXT-kolonnen.
-- MySQL på one.com understøtter ikke native vektorfunktioner, så søgning
-- foregår via PHP (dot-produkt / cosine similarity).
-- =============================================================================

CREATE TABLE IF NOT EXISTS video_embeddings (
    -- Primærnøgle
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Angiver om rækken stammer fra en video-artikel eller en link-artikel
    -- 'video'     = artikel med en indlejret YouTube-video
    -- 'videolink' = artikel der linker til en video-artikel
    source_type ENUM('video', 'videolink') NOT NULL,

    -- ID fra kildedata:
    --   source_type='video'     → article-id fra joomla4_videoer.json
    --   source_type='videolink' → linker_id fra joomla4_videolinks.json
    source_id VARCHAR(20) NOT NULL,

    -- Kun udfyldt for source_type='videolink':
    -- Peger på article-id for den video-artikel brugeren skal vises
    target_article_id VARCHAR(20) NULL,

    -- Kategori fra kildedata (til visning/filtrering)
    category VARCHAR(255) NULL,

    -- Videoens titel (video_title fra kildedata)
    video_title TEXT NOT NULL,

    -- Titel på den artikel der linker til videoen (kun for source_type='videolink')
    linker_title TEXT NULL,

    -- Introtext med HTML fjernet (bruges til popup ved hover/klik på Id-kolonner)
    introtext LONGTEXT NOT NULL,

    -- OpenAI embedding som JSON-array, f.eks. [0.123, -0.456, ...]
    -- text-embedding-3-small producerer 1536 dimensioner
    embedding LONGTEXT NOT NULL,

    -- Tidsstempel for hvornår rækken blev oprettet/opdateret
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Forhindrer dubletter: kombinationen af type, kilde-id og mål-artikel skal være unik.
    -- En linker_id kan godt pege på flere forskellige video-artikler (target_article_id),
    -- så vi inkluderer target_article_id i nøglen for at tillade dette.
    UNIQUE KEY unique_source (source_type, source_id, target_article_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Embeddings til semantisk søgning efter genealogi-videoer';
