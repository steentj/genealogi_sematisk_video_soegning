SELECT
    con.id AS article,
    cat.title AS category,
    con.title as video_title,
    con.introtext
FROM
    joomla4_content con INNER JOIN joomla4_categories cat 
      ON  con.catid = cat.id
WHERE
    LCASE(con.introtext) LIKE '%youtube.com/embed%';