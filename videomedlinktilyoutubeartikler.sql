SELECT 
    youtube_artikler.id AS article,
    linker.id AS linker_id,
    cat.title AS category,
    linker.title as linker_title,
    youtube_artikler.title AS video_title,
    linker.introtext
FROM 
    joomla4_content AS linker
INNER JOIN 
    joomla4_categories AS cat ON linker.catid = cat.id
INNER JOIN 
    joomla4_content AS youtube_artikler 
    ON (linker.introtext LIKE CONCAT('%;id=', youtube_artikler.id, '%') OR 
           linker.introtext LIKE CONCAT('%?id=', youtube_artikler.id, '%'))
WHERE 
    LCASE(youtube_artikler.introtext) LIKE '%youtube.com/embed%'
    AND linker.title <> 'Videovejledninger';