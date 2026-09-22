<?php
$file = '../data/stations-all-today.json';
$gzfile = '../data/stations-all-today.json.gz';
$fp = gzopen($gzfile, 'w9');
gzwrite($fp, file_get_contents($file));
gzclose($fp);
echo "GZ Size: " . round(filesize($gzfile) / 1024, 2) . " KB\n";
